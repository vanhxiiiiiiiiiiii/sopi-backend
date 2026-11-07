<?php

namespace App\Models\Product;

use App\Models\Account\User\User;
use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id', 'product_id', 'created_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
