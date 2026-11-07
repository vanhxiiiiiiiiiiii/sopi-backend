<?php

namespace App\Models\Order;

use App\Models\Address;
use App\Models\Product\Product;
use App\Models\Product\Review;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_code',
        'status_id',
        'recipient_snapshot',
        'stock_committed_at',
        'user_id',
        'shop_id',
        'voucher_id',
        'product_id',
        'product_model_id',
        'received_address_id',
        'pickup_address_id',
        'name',
        'quantity',
        'total',
        'grand_total',
        'subtotal',
        'shipping_fee',
        'discount_amount',
        'payment_total',
        'commission_rate',
        'commission_amount',
        'variations',
    ];

    protected $casts = [
        'variations' => 'array',
        'recipient_snapshot' => 'array',
        'stock_committed_at' => 'datetime',
    ];

    public $timestamps = false;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function receivedAddress()
    {
        return $this->belongsTo(Address::class, 'received_address_id');
    }

    public function pickupAddress()
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    public function progresses()
    {
        return $this->hasMany(Progress::class)->orderBy('created_at')->orderBy('id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function shipment()
    {
        return $this->hasOne(Shipment::class);
    }

    public function voucher()
    {
        return $this->belongsTo(\App\Models\Promotion\Voucher::class);
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }
}
