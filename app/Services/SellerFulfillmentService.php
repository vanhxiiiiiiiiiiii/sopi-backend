<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Account\User\User;
use App\Models\Order\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerFulfillmentService
{
    public function __construct(private OrderWorkflowService $workflow) {}

    public function advance(Order $order, int $sellerId, string $step, ?int $pickupId = null, string $note = ''): Order
    {
        return DB::transaction(function () use ($order, $sellerId, $step, $pickupId, $note) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->shop_id === $sellerId, 403);
            if (!in_array((int) $order->status_id, [1, 2], true)) {
                throw ValidationException::withMessages(['status' => 'Đơn hàng đã rời giai đoạn xử lý của người bán.']);
            }
            if ($order->seller_status === $step) return $order->fresh(['shipment.histories', 'payments', 'items']);
            $previous = ['confirmed' => 'pending', 'preparing' => 'confirmed', 'ready_for_pickup' => 'preparing'];
            if (!isset($previous[$step]) || $order->seller_status !== $previous[$step]) {
                throw ValidationException::withMessages(['status' => 'Hãy xác nhận, chuẩn bị hàng rồi mới bàn giao.']);
            }
            if ($step === 'confirmed') {
                $order = $this->workflow->transition($order, OrderStatus::Ready, $note ?: 'Người bán đã xác nhận đơn hàng.');
            }
            if ($step === 'ready_for_pickup') {
                $address = User::findOrFail($sellerId)->addresses()->where('addresses.id', $pickupId)->first();
                if (!$address) throw ValidationException::withMessages(['pickup_address_id' => 'Hãy chọn địa chỉ lấy hàng của cửa hàng.']);
                $order->pickup_address_id = $address->id;
                $shipment = $order->shipment()->firstOrCreate([], [
                    'tracking_code' => 'SHP-' . Str::upper(Str::random(20)),
                    'carrier' => 'Chờ phân công', 'shipping_fee' => $order->shipping_fee, 'status' => 'READY_FOR_PICKUP',
                ]);
                if ($shipment->wasRecentlyCreated) {
                    $shipment->histories()->create(['new_status' => 'READY_FOR_PICKUP', 'actor_role' => 'SELLER',
                        'actor_id' => $sellerId, 'note' => $note ?: 'Người bán đã chuẩn bị xong, chờ lấy hàng.']);
                }
            }
            $order->seller_status = $step;
            $order->save();
            if ($step !== 'confirmed') {
                $order->progresses()->create(['status_id' => 2,
                    'note' => $note ?: ($step === 'preparing' ? 'Người bán đang chuẩn bị hàng.' : 'Sẵn sàng bàn giao đơn vị vận chuyển.')]);
            }
            return $order->fresh(['shipment.histories', 'payments', 'items', 'progresses']);
        }, 3);
    }
}
