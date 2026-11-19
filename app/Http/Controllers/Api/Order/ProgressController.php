<?php

namespace App\Http\Controllers\Api\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\Progress\CancelOrderRequest;
use App\Http\Requests\Order\Progress\ReadyOrderRequest;
use App\Http\Requests\Order\Progress\UpdateProgressRequest;
use App\Models\Order\Order;
use App\Services\OrderWorkflowService;
use App\Services\SellerFulfillmentService;
use Illuminate\Http\Request;
use App\Models\Account\User\User;

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
        $updatedOrder = app(SellerFulfillmentService::class)->advance($order, $request->user()->id,
            'ready_for_pickup', $request->integer('pickup_address_id'), (string) $request->input('note'));

        return response()->json(['status' => true, 'message' => 'Đã cập nhật bàn giao.', 'data' => $updatedOrder]);
    }

    public function cancel(CancelOrderRequest $request, Order $order)
    {
        $updatedOrder = $this->workflow->transition(
            $order,
            OrderStatus::Cancelled,
            (string) $request->input('note'),
            requiredStatus: $request->is('api/v2/user/orders/*') ? OrderStatus::Processing : null,
            actorRole: $request->is('api/v2/user/orders/*') ? 'CUSTOMER' : ($request->user() instanceof User ? 'SELLER' : 'ADMIN'),
            actorId: $request->user()->id,
        );

        return response()->json(['status' => true, 'message' => 'Đã hủy đơn hàng và hoàn tồn kho theo quy định.', 'data' => $updatedOrder]);
    }

    public function update(UpdateProgressRequest $request, Order $order)
    {
        $status = OrderStatus::from((int) $request->input('status_id'));
        if ($status === OrderStatus::Ready) {
            abort_unless($request->user() instanceof User, 403);
            $updatedOrder = app(SellerFulfillmentService::class)->advance($order, $request->user()->id, 'confirmed', note: (string) $request->input('note'));
        } else {
            $updatedOrder = $this->workflow->transition($order, $status, (string) $request->input('note'),
                actorRole: $request->user() instanceof User ? 'SELLER' : 'ADMIN', actorId: $request->user()->id);
        }

        return response()->json(['status' => true, 'message' => 'Đã cập nhật trạng thái đơn hàng.', 'data' => $updatedOrder]);
    }

    public function confirm(Request $request, Order $order) { return $this->sellerStep($request, $order, 'confirmed'); }
    public function prepare(Request $request, Order $order) { return $this->sellerStep($request, $order, 'preparing'); }
    private function sellerStep(Request $request, Order $order, string $step)
    {
        abort_unless($request->user() instanceof User && (int) $order->shop_id === (int) $request->user()->id, 403);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        return response()->json(['status' => true, 'data' => app(SellerFulfillmentService::class)
            ->advance($order, $request->user()->id, $step, note: $data['note'] ?? '')]);
    }
}
