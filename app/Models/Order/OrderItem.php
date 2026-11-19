<?php

namespace App\Models\Order;

use App\Models\Product\Product;
use App\Models\Product\ProductModel;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_model_id', 'product_code', 'product_name',
        'variations', 'quantity', 'unit_price', 'line_total',
    ];

    protected $casts = ['variations' => 'array'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class);
    }
}
