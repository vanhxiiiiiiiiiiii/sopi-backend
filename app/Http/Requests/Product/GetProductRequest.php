<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\CustomFormRequest;

class GetProductRequest extends CustomFormRequest
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
            'limit' => 'sometimes|integer|min:1|max:200',
            'page' => 'sometimes|integer|min:1',
        ];
    }
}
