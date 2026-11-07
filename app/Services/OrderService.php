<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Account\User\User;
use App\Models\Order\CartItem;
use Illuminate\Support\Facades\Validator;
use App\Models\Order\Order;
use App\Models\Product\ProductModel;
use App\Models\Promotion\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private OrderWorkflowService $workflow, private CartService $cart)
    {
    }

    public function quote(int $userId, ?string $voucherCode, ?array $modelIds = null): array
    {
        $items = $this->cart->getItems($userId);
        if ($modelIds !== null) {
            $items = $items->whereIn('product_model_id', $modelIds)->values();
            if ($items->count() !== count($modelIds)) {
                throw ValidationException::withMessages(['orders' => 'Sản phẩm đã chọn không còn trong giỏ hàng.']);
            }
        }
        if ($items->isEmpty() || $items->contains(fn ($item) => $item->unavailable_reason !== null)) {
            throw ValidationException::withMessages(['orders' => 'Giỏ hàng rỗng hoặc có sản phẩm không khả dụng.']);
        }
        $subtotal = round($items->sum('final_total_price'), 2);
        $firstShop = $items->first()->shop_id;
        $firstSubtotal = round($items->where('shop_id', $firstShop)->sum('final_total_price'), 2);
        $discount = $this->calculateDiscount($this->resolveVoucher($userId, $voucherCode, false), $firstSubtotal);

        return [
            'subtotal' => $subtotal,
            'shipping_fee' => 0,
            'discount_amount' => $discount,
            'payment_total' => round($subtotal - $discount, 2),
        ];
    }

    public function create(int $userId, array $orderData)
    {
        return DB::transaction(function () use ($userId, $orderData) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $requestedItems = collect($orderData['orders'])->mapWithKeys(fn ($item) =>
                [(int) $item['product_model_id'] => (int) $item['quantity']])->sortKeys(SORT_NUMERIC);
            $hash = hash('sha256', json_encode([
                $requestedItems->all(), (int) $orderData['address_id'], $orderData['voucher_code'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $receiptKey = ['user_id' => $userId, 'request_key' => $orderData['request_key']];
            $receipt = DB::table('checkout_receipts')->where($receiptKey)->first();
            if ($receipt) {
                if (!hash_equals($receipt->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => 'Mã đặt hàng đã được dùng với nội dung khác.']);
                }

                return Order::where('user_id', $userId)->whereIn('id', json_decode($receipt->order_ids, true))
                    ->with(['items', 'payments', 'progresses'])->orderBy('id')->get();
            }

            $cartItems = CartItem::where('user_id', $userId)->whereIn('product_model_id', $requestedItems->keys())
                ->orderBy('product_model_id')->lockForUpdate()->get()
                ->mapWithKeys(fn ($item) => [(int) $item->product_model_id => (int) $item->quantity]);
            if ($cartItems->isEmpty() || $cartItems->all() !== $requestedItems->all()) {
                throw ValidationException::withMessages(['orders' => 'Giỏ hàng đã thay đổi. Hãy tải lại và kiểm tra trước khi đặt hàng.']);
            }

            $address = $user->addresses()->where('addresses.id', $orderData['address_id'])->first();
            if (!$address) {
                throw ValidationException::withMessages(['address_id' => 'Hãy chọn địa chỉ giao hàng của bạn.']);
            }
            $recipient = Validator::make($address->only(['full_name', 'phone', 'address', 'town', 'city', 'state']), [
                'full_name' => 'required|string|max:64',
                'phone' => 'required|digits_between:10,11',
                'address' => 'required|string',
                'town' => 'required|string',
                'city' => 'required|string',
                'state' => 'required|string',
            ])->validate();

            $modelIds = $requestedItems->keys();

            $models = ProductModel::query()
                ->whereIn('id', $modelIds)
                ->with(['wholesalePirces', 'inventoryStocks.warehouse', 'product'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($models->count() !== $modelIds->count()) {
                throw ValidationException::withMessages(['orders' => 'One or more products do not exist.']);
            }

            $items = $modelIds->map(function ($modelId) use ($models, $requestedItems) {
                $model = $models[$modelId];
                $quantity = $requestedItems[$modelId];
                if (!$model->product?->shop_id || (int) $model->product->status_id !== ProductStatus::Published->value
                    || $quantity > $model->availableQuantity()) {
                    throw ValidationException::withMessages([
                        'orders' => "Sản phẩm {$model->product?->name} đã ngừng bán hoặc không đủ tồn kho.",
                    ]);
                }

                $unitPrice = $model->unitPriceFor($quantity);

                return [
                    'shop_id' => (int) $model->product->shop_id,
                    'product_id' => (int) $model->product_id,
                    'product_model_id' => (int) $model->id,
                    'product_code' => $model->product->product_code,
                    'product_name' => $model->product->name,
                    'variations' => collect($model->variation_index ?? [])->map(fn ($option, $index) =>
                        $model->product->variations[$index]['options'][$option] ?? $option)->all(),
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'line_total' => round($unitPrice * $quantity, 2),
                ];
            })->groupBy('shop_id');

            $voucher = $this->resolveVoucher($userId, $orderData['voucher_code'] ?? null);
            $createdOrders = collect();

            foreach ($items as $shopId => $shopItems) {
                $subtotal = round((float) $shopItems->sum('line_total'), 2);
                $shippingFee = 0;
                $orderVoucher = $createdOrders->isEmpty() ? $voucher : null;
                $discount = $this->calculateDiscount($orderVoucher, $subtotal);
                $paymentTotal = max(0, round($subtotal + $shippingFee - $discount, 2));
                $commissionRate = 5.00;

                $firstItem = $shopItems->first();
                $order = Order::create([
                    'order_code' => $this->newCode('ORD'),
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'voucher_id' => $orderVoucher?->id,
                    'received_address_id' => $address->id,
                    'recipient_snapshot' => $recipient,
                    'stock_committed_at' => now(),
                    'status_id' => OrderStatus::Processing->value,
                    // Legacy columns are retained for compatibility with the existing frontend.
                    'product_id' => $firstItem['product_id'],
                    'product_model_id' => $firstItem['product_model_id'],
                    'name' => $shopItems->count() === 1 ? $firstItem['product_name'] : 'Multi-item order',
                    'variations' => $firstItem['variations'],
                    'quantity' => $shopItems->sum('quantity'),
                    'total' => $subtotal,
                    'grand_total' => $paymentTotal,
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingFee,
                    'discount_amount' => $discount,
                    'payment_total' => $paymentTotal,
                    'commission_rate' => $commissionRate,
                    'commission_amount' => round($paymentTotal * $commissionRate / 100, 2),
                ]);

                $order->items()->createMany($shopItems->map(fn ($item) => collect($item)->except('shop_id')->all())->all());
                $this->workflow->deductInventory($order);
                $order->progresses()->create([
                    'status_id' => OrderStatus::Processing->value,
                    'note' => 'Order created',
                ]);
                $order->payments()->create([
                    'payment_code' => $this->newCode('PAY'),
                    'method' => 'cod',
                    'amount' => $paymentTotal,
                    'status' => 'pending',
                ]);

                if ($orderVoucher) {
                    DB::table('voucher_usages')->insert([
                        'voucher_id' => $orderVoucher->id,
                        'user_id' => $userId,
                        'order_id' => $order->id,
                        'discount_amount' => $discount,
                        'used_at' => now(),
                    ]);
                    $orderVoucher->increment('used_count');
                }

                DB::table('cart_items')
                    ->where('user_id', $userId)
                    ->whereIn('product_model_id', $shopItems->pluck('product_model_id'))
                    ->delete();

                $createdOrders->push($order->load('items', 'payments', 'progresses'));
            }

            DB::table('checkout_receipts')->insert([
                ...$receiptKey,
                'payload_hash' => $hash,
                'order_ids' => json_encode($createdOrders->pluck('id')->all(), JSON_THROW_ON_ERROR),
            ]);

            return $createdOrders;
        }, 3);
    }

    private function resolveVoucher(int $userId, ?string $code, bool $lock = true): ?Voucher
    {
        if (!$code) {
            return null;
        }

        $voucher = Voucher::where('code', $code)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $now = Carbon::now();
        $userUses = $voucher
            ? DB::table('voucher_usages')->where('voucher_id', $voucher->id)->where('user_id', $userId)->count()
            : 0;

        if (!$voucher || !$voucher->is_active || $now->lt($voucher->starts_at) || $now->gt($voucher->ends_at)
            || $voucher->used_count >= $voucher->max_uses || $userUses >= $voucher->max_uses_per_customer) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher is invalid, expired, or has reached its usage limit.']);
        }

        return $voucher;
    }

    private function calculateDiscount(?Voucher $voucher, float $subtotal): float
    {
        if (!$voucher) {
            return 0;
        }
        if ($subtotal < (float) $voucher->minimum_order_value) {
            throw ValidationException::withMessages(['voucher_code' => 'The order does not meet the voucher minimum value.']);
        }

        $discount = $voucher->discount_type === 'percentage'
            ? $subtotal * (float) $voucher->discount_value / 100
            : (float) $voucher->discount_value;

        if ($voucher->maximum_discount !== null) {
            $discount = min($discount, (float) $voucher->maximum_discount);
        }

        return round(min($discount, $subtotal), 2);
    }

    private function newCode(string $prefix): string
    {
        return $prefix . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(8));
    }
}
