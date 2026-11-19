<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Account\User\User;

class UnavailableGateway implements PaymentGateway
{
    public function available(): bool { return false; }
    public function provider(): ?string { return null; }
    public function setupSession(User $user): array
    {
        abort(503, 'Thanh toán thẻ chưa khả dụng vì chưa có cổng thanh toán được cấu hình.');
    }
}
