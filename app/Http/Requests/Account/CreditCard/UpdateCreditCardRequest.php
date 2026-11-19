<?php

namespace App\Http\Requests\Account\CreditCard;


class UpdateCreditCardRequest extends AuthorizedCreditCardRequest
{
    public function rules(): array
    {
        return ['cardholder_name' => 'required|string|max:64', 'card_number' => 'prohibited', 'cvv' => 'prohibited', 'payment_token_reference' => 'prohibited', 'expiry_date' => 'prohibited'];
    }
}
