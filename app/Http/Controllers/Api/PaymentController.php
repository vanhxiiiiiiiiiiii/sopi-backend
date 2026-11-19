<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function pay(Request $request, Payment $payment)
    {
        abort_unless($payment->order->user_id === $request->user()->id, 404);
        abort(409, 'Chưa có cổng thanh toán trực tuyến. Đơn COD được thu tiền khi giao hàng.');
    }
}
