<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Account\User\User;
use Illuminate\Validation\ValidationException;

class PaymentMethodService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function methods(User $user): array
    {
        $cards = $user->creditCards()->get()->map(function ($card) {
            return [
                'id' => $card->id, 'cardholder_name' => $card->cardholder_name,
                'card_brand' => $card->card_brand, 'last_four' => $card->last_four,
                'expiry_month' => $card->expiry_month, 'expiry_year' => $card->expiry_year,
                'is_default' => $card->is_default, 'is_active' => $card->is_active,
                'expired' => $card->expired(),
                'usable' => false,
                'unavailable_reason' => $card->expired() ? 'Thẻ đã hết hạn.'
                    : (!$card->is_active ? 'Thẻ đã bị vô hiệu hóa.' : 'Cần liên kết lại qua cổng thanh toán khi dịch vụ được kích hoạt.'),
            ];
        });
        return [
            'cod_available' => true, 'card_available' => false,
            'card_unavailable_reason' => 'Thanh toán thẻ chưa khả dụng. Shoppe chưa cấu hình cổng thanh toán trực tuyến.',
            'provider' => $this->gateway->provider(), 'cards' => $cards,
        ];
    }

    public function validateSelection(User $user, array $data): void
    {
        if ($data['payment_method'] === 'cod') {
            if (!empty($data['payment_method_id'])) {
                throw ValidationException::withMessages(['payment_method_id' => 'COD không sử dụng thẻ ngân hàng.']);
            }
            return;
        }
        $card = $user->creditCards()->whereKey($data['payment_method_id'] ?? 0)->first();
        if (!$card) {
            throw ValidationException::withMessages(['payment_method_id' => 'Hãy chọn thẻ thuộc tài khoản của bạn.']);
        }
        if (!$card->is_active || $card->expired()) {
            throw ValidationException::withMessages(['payment_method_id' => 'Thẻ đã hết hạn hoặc không còn hợp lệ.']);
        }
        // No charge adapter/webhook exists in this repository. Fail closed, including if a
        // future setup adapter is registered before payment confirmation is implemented.
        abort(503, 'Thanh toán thẻ chưa khả dụng. Đơn hàng chưa được tạo và chưa trừ tiền.');
    }
}
