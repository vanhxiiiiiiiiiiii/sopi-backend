<?php

namespace App\Http\Requests\Product\Review;

use App\Http\Requests\CustomFormRequest;

class CreateReviewRequest extends CustomFormRequest
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
            'order_id' => 'required|integer|min:1',
            'product_id' => 'sometimes|integer|min:1',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ];
    }

    public function messages()
    {
        return [
            'order_id.required' => 'Vui lòng chọn đơn hàng cần đánh giá.',
            'rating.required' => 'Vui lòng chọn số sao đánh giá.',
            'rating.integer' => 'Số sao đánh giá không hợp lệ.',
            'rating.min' => 'Vui lòng chọn từ 1 đến 5 sao.',
            'rating.max' => 'Vui lòng chọn từ 1 đến 5 sao.',
            'comment.max' => 'Nhận xét tối đa 2.000 ký tự.',
        ];
    }
}
