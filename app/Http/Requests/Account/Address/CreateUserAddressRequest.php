<?php

namespace App\Http\Requests\Account\Address;

class CreateUserAddressRequest extends CreateOrUpdateUserAddressRequest
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
            'full_name' => 'required|string|max:64',
            'phone' => 'required|digits_between:10,11',
            'state' => 'required|string|max:50',
            'city' => 'nullable|string|max:50',
            'town' => 'required|string|max:50',
            'address' => 'required|string',
            'latitude' => 'nullable|required_with:longitude|numeric|between:8,24',
            'longitude' => 'nullable|required_with:latitude|numeric|between:102,110',
            'is_home' => 'boolean',
            'is_pickup_address' => 'boolean',
            'is_default_address' => 'boolean',
            'is_return_address' => 'boolean',
        ];
    }
}
