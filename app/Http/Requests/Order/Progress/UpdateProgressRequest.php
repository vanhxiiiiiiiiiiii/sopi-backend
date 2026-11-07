<?php

namespace App\Http\Requests\Order\Progress;

use App\Enums\AccountType;
use App\Http\Requests\CustomFormRequest;

class UpdateProgressRequest extends CustomFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $isAdmin = get_class($this->user()) === AccountType::Admin->value;
        $isOwningShop = get_class($this->user()) === AccountType::User->value
            && $this->user()->id === $this->route('order')->shop_id;

        return $isAdmin || $isOwningShop;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'status_id' => 'required|integer|in:2,3,4,5,6',
            'note' => 'string',
        ];
    }
}
