<?php

namespace App\Http\Controllers\Api\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\Progress\CancelOrderRequest;
use App\Http\Requests\Order\Progress\ReadyOrderRequest;
use App\Http\Requests\Order\Progress\UpdateProgressRequest;
use App\Models\Order\Order;
use App\Services\OrderWorkflowService;

class ProgressController extends Controller
{
    private OrderWorkflowService $workflow;

    public function __construct(OrderWorkflowService $workflow)
    {
        $this->workflow = $workflow;
        $this->middleware('auth:sanctum');
    }

    public function ready(ReadyOrderRequest $request, Order $order)
    {
        $updatedOrder = $this->workflow->transition(
            $order,
            OrderStatus::Ready,
            (string) $request->input('note'),
            $request->integer('pickup_address_id'),
        );

        return response()->json(['status' => true, 'message' => 'Order confirmed.', 'data' => $updatedOrder]);
    }

    public function cancel(CancelOrderRequest $request, Order $order)
    {
        $updatedOrder = $this->workflow->transition(
            $order,
            OrderStatus::Cancelled,
            (string) $request->input('note'),
            requiredStatus: $request->is('api/v2/user/orders/*') ? OrderStatus::Processing : null,
        );

        return response()->json(['status' => true, 'message' => 'Order cancelled and inventory restored when applicable.', 'data' => $updatedOrder]);
    }

    public function update(UpdateProgressRequest $request, Order $order)
    {
        $status = OrderStatus::from((int) $request->input('status_id'));
        $updatedOrder = $this->workflow->transition($order, $status, (string) $request->input('note'));

        return response()->json(['status' => true, 'message' => 'Order status updated.', 'data' => $updatedOrder]);
    }
}
