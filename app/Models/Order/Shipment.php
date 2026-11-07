<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'tracking_code', 'order_id', 'carrier', 'picked_up_at', 'estimated_delivery_at',
        'delivered_at', 'shipping_fee', 'status',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'estimated_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
