<?php

namespace App\Models\Product;

use App\Models\Shop\Shop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_code',
        'shop_id',
        'brand_id',
        'status_id',
        'name',
        'description',
        'weight',
        'cost_price',
        'unit',
        'images',
        'videos',
        'variations',
    ];

    protected $casts = [
        'images' => 'array',
        'videos' => 'array',
        'variations' => 'array',
    ];

    protected $hidden = [
        'avg_price',
    ];

    public $timestamps = false;

    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'product_category_paths',
            'product_id',
            'category_id',
        );
    }

    public function models()
    {
        return $this->hasMany(ProductModel::class);
    }

    public function wholesalePrices()
    {
        return $this->hasMany(WholesalePrice::class);
    }

    public function attributes()
    {
        return $this->belongsToMany(
            CategoryAttribute::class,
            'product_attributes',
            'product_id',
            'attribute_id',
        )
        ->select(
            'product_category_attributes.name',
            'product_attributes.value as value',
            'product_attributes.unit as unit',
        );
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class)
            ->with('statistic');
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
