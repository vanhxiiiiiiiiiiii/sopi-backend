<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_cardable_id',
        'credit_cardable_type',
        'cardholder_name',
        'provider', 'payment_token_reference', 'card_brand', 'last_four',
        'expiry_month', 'expiry_year', 'is_default', 'is_active',
        'registration_address',
        'postal_code',
    ];

    protected $hidden = [
        'credit_cardable_id',
        'credit_cardable_type',
        'card_number', 'cvv', 'payment_token_reference',
    ];

    public $timestamps = false;
    protected $casts = ['payment_token_reference' => 'encrypted', 'is_default' => 'boolean', 'is_active' => 'boolean'];

    public function expired(): bool
    {
        if (!$this->expiry_month || !$this->expiry_year) return true;
        return (int) $this->expiry_year < (int) now()->year
            || ((int) $this->expiry_year === (int) now()->year && (int) $this->expiry_month < (int) now()->month);
    }
}
