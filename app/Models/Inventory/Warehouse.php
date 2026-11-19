<?php

namespace App\Models\Inventory;

use App\Models\Shop\Shop;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['warehouse_code', 'shop_id', 'name', 'address', 'manager_user_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function stocks()
    {
        return $this->hasMany(InventoryStock::class);
    }
}
