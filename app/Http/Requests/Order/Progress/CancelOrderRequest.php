<?php

namespace App\Http\Requests\Order\Progress;

use App\Enums\AccountType;
use App\Enums\OrderStatus;

class CancelOrderRequest extends UpdateProgressRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        if ($this->is('api/v2/user/orders/*')) {
            return get_class($this->user()) === AccountType::User->value
                && $this->user()->id === $this->route('order')->user_id
                && $this->route('order')->status_id === OrderStatus::Processing->value;
        }

        return parent::authorize();
    }

    public function rules()
    {
        return ['note' => 'nullable|string'];
    }
}
