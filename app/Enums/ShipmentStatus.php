<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case Pending = 'PENDING';
    case Ready = 'READY_FOR_PICKUP';
    case Assigned = 'ASSIGNED';
    case PickedUp = 'PICKED_UP';
    case InTransit = 'IN_TRANSIT';
    case OutForDelivery = 'OUT_FOR_DELIVERY';
    case Delivered = 'DELIVERED';
    case Failed = 'DELIVERY_FAILED';
    case Returning = 'RETURNING';
    case Returned = 'RETURNED';
    case Cancelled = 'CANCELLED';

    public function next(): array
    {
        return match ($this) {
            self::Assigned => [self::PickedUp],
            self::PickedUp => [self::InTransit],
            self::InTransit => [self::OutForDelivery],
            self::OutForDelivery => [self::Delivered, self::Failed],
            self::Failed => [self::OutForDelivery, self::Returning],
            self::Returning => [self::Returned],
            default => [],
        };
    }
}
