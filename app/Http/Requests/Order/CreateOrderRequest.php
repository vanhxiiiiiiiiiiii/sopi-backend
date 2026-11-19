<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\CustomFormRequest;

class CreateOrderRequest extends CustomFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'request_key' => 'required|uuid',
            'orders' => 'required|array|min:1|max:100',
            'orders.*.product_model_id' => 'required|integer|min:1|distinct',
            'orders.*.quantity' => 'required|integer|min:1',
            'address_id' => 'required|integer|min:1',
            'expected_payment_total' => 'sometimes|numeric|min:0|decimal:0,2',
            'delivery_location' => 'sometimes|array:latitude,longitude|required_array_keys:latitude,longitude',
            'delivery_location.latitude' => 'required_with:delivery_location|numeric|between:8,24',
            'delivery_location.longitude' => 'required_with:delivery_location|numeric|between:102,110',
            'voucher_code' => 'nullable|string|max:50',
            'payment_method' => 'required|in:cod,card',
            'payment_method_id' => 'nullable|required_if:payment_method,card|integer|min:1',
            'card_number' => 'prohibited',
            'cvv' => 'prohibited',
            'payment_token_reference' => 'prohibited',
        ];
    }
}
