<?php

namespace App\Contracts;

use App\Models\Account\User\User;

interface PaymentGateway
{
    public function available(): bool;
    public function provider(): ?string;
    // A real adapter must return a provider-hosted setup session. Never collect PAN/CVV here.
    public function setupSession(User $user): array;
}
