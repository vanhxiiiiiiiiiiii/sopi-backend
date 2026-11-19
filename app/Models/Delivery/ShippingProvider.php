<?php

namespace App\Models\Delivery;

use Illuminate\Database\Eloquent\Model;

class ShippingProvider extends Model
{
    protected $fillable = ['code', 'name', 'phone', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
