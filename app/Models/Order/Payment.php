<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['payment_code', 'order_id', 'method', 'amount', 'paid_at', 'status', 'metadata'];
    protected $casts = ['paid_at' => 'datetime', 'metadata' => 'array'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
