<?php

namespace App\Models\Delivery;

use Illuminate\Database\Eloquent\Model;

class ShipmentHistory extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_key', 'request_hash'];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime'];

    public function shipment() { return $this->belongsTo(\App\Models\Order\Shipment::class); }
}
