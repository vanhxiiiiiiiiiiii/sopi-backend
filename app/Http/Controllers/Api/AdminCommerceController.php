<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product\Product;
use App\Models\Order\Order;
use App\Models\Order\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCommerceController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['q' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:100']);
    }

    public function products(Request $request)
    {
        $data = $this->filters($request);
        return response()->json(['data' => Product::with('shop:id,name')
            ->when($data['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) =>
                $q->where('name', 'like', '%' . $text . '%')->orWhere('product_code', 'like', '%' . $text . '%')))
            ->orderByDesc('id')->paginate($data['limit'] ?? 15)]);
    }

    public function moderate(Request $request, Product $product)
    {
        $data = $request->validate(['status_id' => 'required|integer|in:1,2,3', 'note' => 'required|string|min:5|max:1000']);
        $product = DB::transaction(function () use ($request, $product, $data) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $product->status_id;
            $product->update(['status_id' => $data['status_id']]);
            DB::table('admin_activity_logs')->insert(['actor_id' => $request->user()->id, 'action' => 'product_moderated',
                'subject_type' => 'product', 'subject_id' => $product->id,
                'details' => json_encode(['before' => $before, 'after' => $data['status_id'], 'note' => $data['note']], JSON_THROW_ON_ERROR)]);
            return $product;
        }, 3);
        return response()->json(['data' => $product]);
    }

    public function orders(Request $request)
    {
        $data = $this->filters($request);
        return response()->json(['data' => Order::with(['shop:id,name', 'items', 'payments', 'shipment.histories', 'progresses'])
            ->when($data['q'] ?? null, fn ($q, $text) => $q->where('order_code', 'like', '%' . $text . '%'))
            ->orderByDesc('id')->paginate($data['limit'] ?? 15)]);
    }

    public function payments(Request $request)
    {
        $data = $this->filters($request);
        return response()->json(['data' => Payment::with('order:id,order_code,status_id')
            ->when($data['q'] ?? null, fn ($q, $text) => $q->where('payment_code', 'like', '%' . $text . '%'))
            ->orderByDesc('id')->paginate($data['limit'] ?? 15)]);
    }
}
