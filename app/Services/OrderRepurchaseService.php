<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Account\User\User;
use App\Models\Order\CartItem;
use App\Models\Order\Order;
use App\Models\Product\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderRepurchaseService
{
    public function addToCart(int $userId, int $orderId)
    {
        return DB::transaction(function () use ($userId, $orderId) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($orderId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ((int) $order->status_id !== OrderStatus::Delivered->value) {
                throw ValidationException::withMessages(['order' => 'Chỉ có thể mua lại từ đơn hàng đã hoàn thành.']);
            }
            $items = $order->items->isNotEmpty() ? $order->items : collect([$order]);
            $cart = app(CartService::class);
            foreach ($items->sortBy('product_model_id')->groupBy('product_model_id') as $modelId => $lines) {
                $model = ProductModel::with('product.shop')->whereKey($modelId)->lockForUpdate()->first();
                if (!$model || !$model->product?->shop?->is_active
                    || (int) $model->product_id !== (int) $lines->first()->product_id) {
                    throw ValidationException::withMessages(['order' => 'Một sản phẩm hoặc phân loại trong đơn không còn bán. Giỏ hàng chưa được thay đổi.']);
                }
                // Repeated clicks must not multiply quantities or reduce an existing cart line.
                $quantity = max((int) $lines->sum('quantity'), (int) CartItem::where('user_id', $userId)
                    ->where('product_model_id', $modelId)->value('quantity'));
                $cart->addItem($userId, ['product_model_id' => (int) $modelId, 'quantity' => $quantity]);
            }
            return $cart->getItems($userId);
        }, 3);
    }
}
