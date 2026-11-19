<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Account\Admin\Admin;
use App\Models\Delivery\DeliveryStaff;
use App\Models\Order\Order;
use App\Models\Order\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentService
{
    public function __construct(private OrderWorkflowService $workflow) {}

    public function canAssign(Shipment $shipment): bool
    {
        if (in_array($shipment->status, ['READY_FOR_PICKUP', 'ASSIGNED'], true)) {
            return true;
        }

        $shipment->loadMissing(['shipper.provider', 'order.payments']);
        if ($shipment->shipper?->is_active && $shipment->shipper->provider?->is_active) {
            return false;
        }

        if (in_array($shipment->status, ['PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED', 'RETURNING'], true)) {
            return true;
        }

        // A delivered parcel may only be reassigned to recover an outstanding COD payment.
        return $shipment->status === 'DELIVERED'
            && (int) $shipment->order->status_id === OrderStatus::Delivered->value
            && $shipment->order->payments->contains(fn ($payment) => $payment->method === 'cod' && $payment->status === 'pending');
    }

    public function mutate(Shipment $original, $actor, string $action, array $data): Shipment
    {
        return DB::transaction(function () use ($original, $actor, $action, $data) {
            // Every writer locks order before shipment, including seller cancellation.
            $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $shipment->setRelation('order', $order);
            $admin = $actor instanceof Admin;
            if (!$admin) {
                abort_unless($actor instanceof DeliveryStaff && $actor->is_active && $actor->provider?->is_active
                    && (int) $shipment->shipper_id === (int) $actor->id
                    && (int) $shipment->shipping_provider_id === (int) $actor->shipping_provider_id, 403);
            }
            if ($action === 'assign' || $action === 'request-return') abort_unless($admin, 403);
            if ($action === 'status' || $action === 'cod') abort_if($admin, 403, 'Chỉ nhân viên được phân công được xác nhận nghiệp vụ này.');
            $hash = hash('sha256', json_encode([$action, $actor::class, $actor->id, $data], JSON_THROW_ON_ERROR));
            $event = $shipment->histories()->where('request_key', $data['request_key'])->first();
            if ($event) {
                abort_unless(hash_equals($event->getRawOriginal('request_hash'), $hash), 409, 'Mã yêu cầu đã được sử dụng với nội dung khác.');
                return $shipment;
            }
            abort_if((int) $order->status_id === 5 || $shipment->status === 'CANCELLED', 409, 'Đơn hàng đã hủy.');
            abort_unless($shipment->version === (int) $data['expected_version'], 409, 'Vận đơn đã thay đổi. Hãy tải lại trước khi thao tác.');
            $previous = $shipment->status;
            $note = $data['note'] ?? null;
            if ($action === 'assign') {
                abort_unless($this->canAssign($shipment), 409, 'Không thể phân công lại ở giai đoạn này.');
                $recovery = !in_array($previous, ['READY_FOR_PICKUP', 'ASSIGNED'], true);
                if ($recovery && trim($note ?? '') === '') {
                    throw ValidationException::withMessages(['note' => 'Hãy ghi lý do tiếp quản vận đơn hoặc khoản COD tồn đọng.']);
                }
                $previousShipper = $shipment->shipper_id;
                $staff = DeliveryStaff::with('provider')->lockForUpdate()->findOrFail($data['shipper_id']);
                if (!$staff->is_active || !$staff->provider?->is_active) {
                    throw ValidationException::withMessages(['shipper_id' => 'Nhân viên hoặc đơn vị vận chuyển đã bị vô hiệu hóa.']);
                }
                $shipment->shipper_id = $staff->id;
                $shipment->shipping_provider_id = $staff->shipping_provider_id;
                $shipment->carrier = $staff->provider->name;
                $shipment->assigned_at = now();
                if (in_array($previous, ['READY_FOR_PICKUP', 'ASSIGNED'], true)) $shipment->status = 'ASSIGNED';
                $note = ($note ? $note . ' · ' : '') . 'Phân công cho ' . $staff->name . ' (' . $staff->provider->name . ').';
                if ($recovery) {
                    $note .= ' Nhân viên trước: ' . ($previousShipper ?? 'chưa phân công') . '.';
                }
                if ($previous === 'DELIVERED') {
                    $note .= ' Tiếp quản COD tồn đọng; giữ nguyên kết quả giao hàng, chưa xác nhận thu tiền.';
                }
            } elseif ($action === 'cod') {
                abort_unless($previous === 'DELIVERED', 409, 'Chỉ xác nhận thu COD sau khi giao hàng thành công.');
                $payment = $order->payments()->where('method', 'cod')->lockForUpdate()->firstOrFail();
                abort_unless($payment->status === 'pending', 409, 'Khoản COD đã được xác nhận hoặc không còn khả dụng.');
                if (round((float) $data['amount'], 2) !== round((float) $payment->amount, 2)) {
                    throw ValidationException::withMessages(['amount' => 'Số tiền thu phải bằng số COD của đơn hàng.']);
                }
                DB::table('cod_collections')->insert([
                    'shipment_id' => $shipment->id, 'payment_id' => $payment->id, 'shipper_id' => $actor->id,
                    'amount' => $payment->amount, 'receipt_reference' => $data['receipt_reference'], 'collected_at' => now(),
                ]);
                $payment->update(['status' => 'paid', 'paid_at' => now()]);
                $note = 'Đã xác nhận thu COD. Biên nhận: ' . $data['receipt_reference'];
            } else {
                if ($action === 'request-return') {
                    abort_unless(in_array($previous, ['DELIVERED', 'DELIVERY_FAILED'], true) && $shipment->shipper_id, 409, 'Vận đơn chưa đủ điều kiện hoàn hàng.');
                    $next = ShipmentStatus::Returning;
                } else {
                    $current = ShipmentStatus::tryFrom($previous);
                    $next = ShipmentStatus::from($data['status']);
                    if (!$current || !in_array($next, $current->next(), true)) {
                        throw ValidationException::withMessages(['status' => 'Không thể bỏ qua hoặc lặp lại bước vận chuyển.']);
                    }
                }
                if ($next === ShipmentStatus::Failed) {
                    if (empty($data['failed_reason'])) throw ValidationException::withMessages(['failed_reason' => 'Hãy chọn lý do giao hàng thất bại.']);
                    $shipment->failed_reason = $data['failed_reason'];
                    $note = trim(($note ?? '') . ' · ' . $data['failed_reason'], ' ·');
                }
                $shipment->status = $next->value;
                if ($next === ShipmentStatus::PickedUp) {
                    $shipment->picked_up_at = now();
                    $order->seller_status = 'handed_over';
                    $order->save();
                    $this->workflow->transition($order, OrderStatus::Delivering, 'Nhân viên vận chuyển đã lấy hàng.', deliveryVerified: true);
                }
                if ($next === ShipmentStatus::Delivered) {
                    $shipment->delivered_at = now();
                    $this->workflow->transition($order, OrderStatus::Delivered, 'Nhân viên được phân công xác nhận giao thành công; COD được đối soát riêng.', deliveryVerified: true);
                }
                if ($next === ShipmentStatus::Returned) {
                    $this->workflow->transition($order, OrderStatus::Return, 'Đã hoàn hàng về người bán.', deliveryVerified: true);
                }
            }
            $shipment->version++;
            $shipment->save();
            $shipment->histories()->create([
                'previous_status' => $previous, 'new_status' => $shipment->status,
                'actor_role' => $admin ? 'ADMIN' : 'SHIPPER', 'actor_id' => $actor->id,
                'event_type' => $action, 'note' => $note,
                'request_key' => $data['request_key'], 'request_hash' => $hash,
            ]);
            return $shipment->fresh();
        }, 3);
    }

    public function present(Shipment $shipment): array
    {
        $shipment->loadMissing(['order.items', 'order.shop:id,name', 'order.receivedAddress', 'order.pickupAddress', 'order.payments', 'shipper.provider', 'histories']);
        $order = $shipment->order;
        $cod = $order->payments->firstWhere('method', 'cod');
        return [
            'id' => $shipment->id, 'tracking_code' => $shipment->tracking_code, 'status' => $shipment->status,
            'version' => $shipment->version, 'carrier' => $shipment->carrier, 'shipping_fee' => $shipment->shipping_fee,
            'shipping_provider_id' => $shipment->shipping_provider_id, 'shipper_id' => $shipment->shipper_id,
            'shipper_name' => $shipment->shipper?->name, 'assigned_at' => $shipment->assigned_at,
            'assignment_active' => (bool) ($shipment->shipper?->is_active && $shipment->shipper?->provider?->is_active),
            'can_assign' => $this->canAssign($shipment),
            'picked_up_at' => $shipment->picked_up_at, 'delivered_at' => $shipment->delivered_at,
            'failed_reason' => $shipment->failed_reason,
            'order' => ['id' => $order->id, 'order_code' => $order->order_code, 'status_id' => $order->status_id,
                'seller_status' => $order->seller_status, 'shop_id' => $order->shop_id, 'shop_name' => $order->shop?->name,
                'recipient' => $order->recipient_snapshot ?: $order->receivedAddress?->only(['full_name', 'phone', 'address', 'town', 'city', 'state']),
                'pickup_address' => $order->pickupAddress?->only(['full_name', 'phone', 'address', 'town', 'city', 'state']),
                'items' => $order->items->map->only(['product_name', 'quantity', 'variations']),
                'payment_method' => $order->payments->first()?->method,
                'payment_status' => $order->payments->first()?->status,
                'cod_due' => $cod && $cod->status === 'pending' ? $cod->amount : 0,
            ],
            'histories' => $shipment->histories,
            'allowed_statuses' => array_map(fn ($status) => $status->value, ShipmentStatus::tryFrom($shipment->status)?->next() ?? []),
        ];
    }
}
