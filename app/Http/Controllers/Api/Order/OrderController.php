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
                'shop:id,name,phone,email',
                'reviews',
                'product.shop:id,name',
                'receivedAddress',
                'pickupAddress',
                'progresses',
                'payments',
                'shipment.histories',
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

        if (isset($orderQuery['purchase_status'])) {
            match ($orderQuery['purchase_status']) {
                'unpaid' => $orders->whereIn('status_id', [1, 2])->whereHas('payments', fn ($query) =>
                    $query->where('status', 'pending')->where('method', '<>', 'cod')),
                'shipping' => $orders->whereIn('status_id', [1, 2])->whereDoesntHave('payments', fn ($query) =>
                    $query->where('status', 'pending')->where('method', '<>', 'cod')),
                'delivery' => $orders->where('status_id', 3),
                'completed' => $orders->where('status_id', 4),
                'cancelled' => $orders->where('status_id', 5),
                'returned' => $orders->where('status_id', 6),
            };
        }
        $orders = $orders
            ->orderByDesc('id')
            ->with(['items.product', 'shop:id,name,phone,email', 'reviews', 'product.shop:id,name', 'receivedAddress', 'pickupAddress', 'payments', 'shipment.histories'])
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
            ->with(['items', 'product', 'receivedAddress', 'pickupAddress', 'payments', 'shipment.histories'])
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
            'address_id' => 'nullable|required_without:delivery_location|integer|min:1',
            'delivery_location' => 'sometimes|array:latitude,longitude|required_array_keys:latitude,longitude',
            'delivery_location.latitude' => 'required_with:delivery_location|numeric|between:8,24',
            'delivery_location.longitude' => 'required_with:delivery_location|numeric|between:102,110',
        ]);

        return response()->json(['status' => true,
            'data' => $this->orderService->quote($request->user()->id, $data['voucher_code'] ?? null, $data['model_ids'] ?? null,
                $data['address_id'] ?? null, $data['delivery_location'] ?? null)]);
    }

    public function buyAgain(Request $request, int $order)
    {
        $items = app(\App\Services\OrderRepurchaseService::class)->addToCart($request->user()->id, $order);
        return response()->json(['status' => true, 'message' => 'Đã thêm sản phẩm vào giỏ hàng với giá hiện tại.', 'data' => $items]);
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
