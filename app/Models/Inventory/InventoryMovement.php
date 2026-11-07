<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'warehouse_id', 'product_model_id', 'order_id', 'movement_type',
        'quantity_change', 'quantity_after', 'note', 'created_at',
    ];
}
