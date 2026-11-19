<?php

namespace App\Http\Requests\Account\CreditCard;

use App\Http\Requests\CustomFormRequest;

class CreateCreditCardRequest extends CustomFormRequest
{
    public function authorize() { return true; }
    public function rules(): array
    {
        return ['card_number' => 'prohibited', 'cvv' => 'prohibited', 'payment_token_reference' => 'prohibited'];
    }
}
