<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductOrder;
use App\Models\Product\Category;
use App\Rules\BatchExistsRule;

class SearchProductRequest extends GetProductRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            ...parent::rules(),
            'keyword' => 'nullable|string|max:120',
            'filter.category_ids' => ['sometimes', 'array', new BatchExistsRule(Category::class, null, 'id')],
            'filter.category_ids.*' => 'integer|min:1',
            'order_by' => 'sometimes|integer|min:1|max:' . count(ProductOrder::cases()),
        ];
    }
}
