<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    public $timestamps = false;
    protected $table = 'product_price_histories';
    protected $fillable = ['product_model_id', 'old_price', 'new_price', 'effective_at'];
    protected $casts = ['effective_at' => 'datetime'];
}
