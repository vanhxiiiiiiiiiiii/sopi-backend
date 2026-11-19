<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'tracking_code', 'order_id', 'carrier', 'picked_up_at', 'estimated_delivery_at',
        'delivered_at', 'shipping_fee', 'status', 'shipping_provider_id', 'shipper_id',
        'assigned_at', 'failed_reason', 'version',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'estimated_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'assigned_at' => 'datetime',
        'version' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function histories()
    {
        return $this->hasMany(\App\Models\Delivery\ShipmentHistory::class)->orderBy('id');
    }

    public function shipper()
    {
        return $this->belongsTo(\App\Models\Delivery\DeliveryStaff::class);
    }

    public function provider()
    {
        return $this->belongsTo(\App\Models\Delivery\ShippingProvider::class, 'shipping_provider_id');
    }
}
