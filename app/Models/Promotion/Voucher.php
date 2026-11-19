<?php

namespace App\Models\Promotion;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'code', 'name', 'discount_type', 'discount_value', 'minimum_order_value',
        'maximum_discount', 'starts_at', 'ends_at', 'max_uses',
        'max_uses_per_customer', 'used_count', 'is_active',
    ];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
}
