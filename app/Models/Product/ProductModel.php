<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'stock',
        'variation_index',
        'is_default',
    ];

    protected $casts = [
        'variation_index' => 'array',
    ];

    public $timestamps = false;

    public function wholesalePirces()
    {
        return $this->hasMany(WholesalePrice::class, 'product_id', 'product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryStocks()
    {
        return $this->hasMany(\App\Models\Inventory\InventoryStock::class);
    }

    public function availableQuantity(): int
    {
        if ($this->inventoryStocks->isEmpty()) {
            return (int) $this->stock;
        }

        $available = $this->inventoryStocks->sum(fn ($stock) => $stock->warehouse?->is_active
            ? max(0, $stock->quantity - $stock->reserved_quantity)
            : 0);

        return min((int) $this->stock, $available);
    }

    public function unitPriceFor(int $quantity): float
    {
        $wholesale = $this->wholesalePirces->where('min', '<=', $quantity)->firstWhere('max', '>=', $quantity);

        return round((float) ($wholesale?->price ?? $this->price), 2);
    }

    public function priceHistories()
    {
        return $this->hasMany(PriceHistory::class)->orderByDesc('effective_at');
    }
}
