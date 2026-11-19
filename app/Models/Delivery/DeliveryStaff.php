<?php

namespace App\Models\Delivery;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class DeliveryStaff extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'delivery_staff';
    protected $fillable = ['shipping_provider_id', 'username', 'email', 'name', 'phone', 'password', 'is_active'];
    protected $hidden = ['password'];
    protected $casts = ['password' => 'hashed', 'is_active' => 'boolean'];

    public function provider()
    {
        return $this->belongsTo(ShippingProvider::class, 'shipping_provider_id');
    }
}
