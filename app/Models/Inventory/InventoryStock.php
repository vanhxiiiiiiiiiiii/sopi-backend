<?php

namespace App\Models\Inventory;

use App\Models\Product\ProductModel;
use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    protected $fillable = ['warehouse_id', 'product_model_id', 'quantity', 'reserved_quantity'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class);
    }
}
