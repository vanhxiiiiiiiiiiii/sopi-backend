<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentMethodService;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ValidatesOperationalInput;
    public function index(Request $request, PaymentMethodService $methods)
    {
        return response()->json(['status' => true, 'data' => $methods->methods($request->user())]);
    }

    public function setup(Request $request, PaymentGateway $gateway)
    {
        $this->validated($request, ['card_number' => 'prohibited', 'cvv' => 'prohibited', 'payment_token_reference' => 'prohibited']);
        return response()->json(['status' => true, 'data' => $gateway->setupSession($request->user())]);
    }
}
