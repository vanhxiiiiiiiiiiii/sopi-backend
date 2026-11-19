<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Product\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductReviewService
{
    /**
     * Create the review.
     *
     * @param int $userId
     * @param array $data
     * @return Review
     */
    public function create(int $userId, array $data): Review
    {
        return DB::transaction(function () use ($userId, $data) {
            $order = Order::whereKey($data['order_id'])->where('user_id', $userId)
                ->lockForUpdate()->firstOrFail();
            if ((int) $order->status_id !== OrderStatus::Delivered->value) {
                throw ValidationException::withMessages(['order_id' => 'Chỉ có thể đánh giá đơn hàng đã hoàn thành.']);
            }
            $items = $order->items;
            $productIds = $items->isNotEmpty() ? $items->pluck('product_id')->unique() : collect([$order->product_id]);
            if (!isset($data['product_id']) && $productIds->count() > 1) {
                throw ValidationException::withMessages(['product_id' => 'Vui lòng chọn sản phẩm trong đơn cần đánh giá.']);
            }
            $productId = (int) ($data['product_id'] ?? $productIds->first());
            if (!$productIds->contains($productId)) {
                throw ValidationException::withMessages(['product_id' => 'Sản phẩm không thuộc đơn hàng này.']);
            }
            $comment = trim($data['comment'] ?? '');
            $existing = $order->reviews()->where('product_id', $productId)->first();
            if ($existing) {
                // Retrying after a lost response must not duplicate the review.
                abort_unless((int) $existing->rating === (int) $data['rating'] && $existing->comment === $comment,
                    409, 'Bạn đã đánh giá sản phẩm này trong đơn hàng.');
                return $existing;
            }
            return Review::create([
                'user_id' => $userId, 'order_id' => $order->id, 'product_id' => $productId,
                'shop_id' => $order->shop_id, 'rating' => $data['rating'], 'comment' => $comment,
                'variations' => $items->firstWhere('product_id', $productId)?->variations ?? $order->variations ?? [],
            ]);
        }, 3);
    }
}
