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
            'voucher_code' => 'nullable|string|max:50',
            'payment_method' => 'required|in:cod',
        ];
    }
}
