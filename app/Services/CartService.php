<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Models\Account\User\User;
use App\Models\Order\CartItem;
use App\Models\Product\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function getItems(int $userId)
    {
        return CartItem::where('user_id', $userId)
            ->with(['shop', 'product', 'productModel.wholesalePirces', 'productModel.inventoryStocks.warehouse'])
            ->orderBy('product_model_id')->get()->each(function (CartItem $item) {
                $available = $item->productModel?->availableQuantity() ?? 0;
                $price = $item->productModel?->unitPriceFor($item->quantity) ?? 0;
                $item->unit_price = $price;
                $item->available_quantity = $available;
                $item->total_price = $item->final_total_price = round($price * $item->quantity, 2);
                $item->unavailable_reason = match (true) {
                    !$item->productModel, !$item->product?->shop_id,
                    (int) $item->product?->status_id !== ProductStatus::Published->value => 'Sản phẩm không còn bán.',
                    $available <= 0 => 'Sản phẩm đã hết hàng.',
                    $item->quantity > $available => "Chỉ còn {$available} sản phẩm.",
                    default => null,
                };
            });
    }

    public function addItem(int $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $model = ProductModel::whereKey($data['product_model_id'])
                ->with(['product', 'inventoryStocks.warehouse', 'wholesalePirces'])->lockForUpdate()->first();

            if (!$model?->product?->shop_id || (int) $model->product->status_id !== ProductStatus::Published->value
                || $data['quantity'] > $model->availableQuantity()) {
                throw ValidationException::withMessages(['quantity' => 'Sản phẩm không còn bán hoặc không đủ tồn kho.']);
            }

            $cartId = DB::table('carts')->where('user_id', $userId)->where('status', 'active')->value('id')
                ?? DB::table('carts')->insertGetId([
                    'user_id' => $userId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $item = CartItem::where('user_id', $userId)->where('product_model_id', $model->id);
            if ($item->exists()) {
                $item->update(['quantity' => $data['quantity'], 'cart_id' => $cartId]);
            } else {
                CartItem::create([...$data, 'user_id' => $userId, 'cart_id' => $cartId]);
            }

            $price = $model->unitPriceFor($data['quantity']);

            return ['unit_price' => $price, 'total_price' => round($price * $data['quantity'], 2),
                'final_total_price' => round($price * $data['quantity'], 2)];
        }, 3);
    }

    public function removeItem(int $userId, int $modelId): void
    {
        DB::transaction(function () use ($userId, $modelId) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            CartItem::where('user_id', $userId)->where('product_model_id', $modelId)->delete();
        }, 3);
    }
}
