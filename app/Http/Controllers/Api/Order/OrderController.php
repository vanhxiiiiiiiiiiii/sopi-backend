<?php

namespace App\Http\Controllers\Api\Order;

use App\Enums\Pagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Requests\Order\GetOrderListRequest;
use App\Models\Order\Order;
use App\Services\OrderService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;

        $this->middleware('auth:sanctum');
    }

    /**
     * Get the order by id.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(int $id)
    {
        $order = Order::where([
            ['user_id', request()->user()->id],
            ['id', $id],
        ])
            ->with([
                'items.product',
                'product.shop:id,name',
                'receivedAddress',
                'pickupAddress',
                'progresses',
                'payments',
                'shipment',
                'voucher',
            ])
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => $order,
        ]);
    }

    /**
     * Get the orders of the current user by status.
     *
     * @param \App\Http\Requests\Order\GetOrderListRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function belongToUser(GetOrderListRequest $request)
    {
        $orderQuery = $request->validated();

        $orders = !isset($orderQuery['status_id'])
            ? Order::where('user_id', $request->user()->id)
            : Order::where([
                ['user_id', $request->user()->id],
                ['status_id', $orderQuery['status_id']]
            ]);

        $orders = $orders
            ->orderByDesc('id')
            ->with(['items.product', 'product.shop:id,name', 'receivedAddress', 'pickupAddress', 'payments', 'shipment'])
            ->paginate($orderQuery['limit'] ?? Pagination::Default->value);

        return response()->json([
            'status' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Get the orders of the current shop by status.
     *
     * @param \App\Http\Requests\Order\GetOrderListRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function belongToShop(GetOrderListRequest $request)
    {
        abort_unless($request->user()->shop, 403);
        $orderQuery = $request->validated();

        $orders = !isset($orderQuery['status_id'])
            ? Order::where('shop_id', $request->user()->id)
            : Order::where([
                ['shop_id', $request->user()->id],
                ['status_id', $orderQuery['status_id']]
            ]);

        $orders = $orders
            ->orderByDesc('id')
            ->with(['items', 'product', 'receivedAddress', 'payments', 'shipment'])
            ->paginate($orderQuery['limit'] ?? Pagination::Default->value);

        return response()->json([
            'status' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Create new orders for the current users.
     *
     * @param \App\Http\Requests\Order\CreateOrderRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'voucher_code' => 'nullable|string|max:50',
            'model_ids' => 'sometimes|array|min:1|max:100',
            'model_ids.*' => 'required|integer|min:1|distinct',
        ]);

        return response()->json(['status' => true,
            'data' => $this->orderService->quote($request->user()->id, $data['voucher_code'] ?? null, $data['model_ids'] ?? null)]);
    }

    public function create(CreateOrderRequest $request)
    {
        $orders = $this->orderService->create(
            $request->user()->id,
            $request->validated(),
        );

        return response()->json([
            'status' => true,
            'message' => 'Orders have been created!',
            'data' => $orders,
        ], Response::HTTP_CREATED);
    }
}
