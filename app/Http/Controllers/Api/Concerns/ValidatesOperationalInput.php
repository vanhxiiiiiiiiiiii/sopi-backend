<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

trait ValidatesOperationalInput
{
    protected function validated(Request $request, array $rules): array
    {
        return $request->validate($rules, [
            'required' => 'Vui lòng nhập :attribute.', 'required_if' => 'Vui lòng nhập :attribute.',
            'prohibited' => 'Không được gửi :attribute trực tiếp đến máy chủ.',
            'string' => ':attribute phải là văn bản.', 'integer' => ':attribute phải là số nguyên.',
            'numeric' => ':attribute phải là số hợp lệ.', 'boolean' => ':attribute không hợp lệ.',
            'email' => 'Địa chỉ email không hợp lệ.', 'uuid' => 'Mã yêu cầu không hợp lệ.',
            'exists' => ':attribute không còn khả dụng.', 'unique' => ':attribute đã được sử dụng.',
            'in' => ':attribute không hợp lệ.', 'alpha_dash' => ':attribute chỉ được gồm chữ, số, dấu gạch ngang hoặc gạch dưới.',
            'confirmed' => 'Mật khẩu nhập lại không khớp.', 'accepted' => 'Bạn cần xác nhận đã thu đủ tiền thực tế.',
            'min.string' => ':attribute cần ít nhất :min ký tự.', 'max.string' => ':attribute không được quá :max ký tự.',
            'min.numeric' => ':attribute phải từ :min trở lên.', 'max.numeric' => ':attribute không được vượt quá :max.',
            'digits_between' => ':attribute cần từ :min đến :max chữ số.',
        ], [
            'usernameOrEmail' => 'tên đăng nhập hoặc email', 'password' => 'mật khẩu', 'username' => 'tên đăng nhập',
            'email' => 'email', 'name' => 'họ tên / tên đơn vị', 'phone' => 'số điện thoại', 'code' => 'mã đơn vị',
            'shipping_provider_id' => 'đơn vị vận chuyển', 'shipper_id' => 'nhân viên vận chuyển',
            'status' => 'trạng thái', 'failed_reason' => 'lý do giao thất bại', 'note' => 'ghi chú',
            'request_key' => 'mã yêu cầu', 'expected_version' => 'phiên bản vận đơn', 'amount' => 'số tiền thu',
            'receipt_reference' => 'mã biên nhận', 'card_number' => 'số thẻ đầy đủ', 'cvv' => 'mã bảo mật thẻ',
        ]);
    }
}
