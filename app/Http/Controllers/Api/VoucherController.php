<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->only('available');
    }

    public function available(Request $request)
    {
        $usage = DB::table('voucher_usages')
            ->selectRaw('COUNT(*)')
            ->whereColumn('voucher_id', 'vouchers.id')
            ->where('user_id', $request->user()->id);

        $vouchers = Voucher::query()
            ->select(['id', 'code', 'name', 'discount_type', 'discount_value',
                'minimum_order_value', 'maximum_discount', 'starts_at', 'ends_at',
                'max_uses', 'max_uses_per_customer', 'used_count', 'is_active'])
            ->selectSub($usage, 'customer_used_count')
            ->orderByDesc('ends_at')
            ->get();

        return response()->json(['status' => true, 'data' => $vouchers]);
    }

    public function index()
    {
        return response()->json(['status' => true, 'data' => Voucher::orderByDesc('created_at')->get()]);
    }

    public function store(Request $request)
    {
        $voucher = Voucher::create($this->validated($request));
        return response()->json(['status' => true, 'data' => $voucher], 201);
    }

    public function update(Request $request, Voucher $voucher)
    {
        $voucher->update($this->validated($request, $voucher->id));
        return response()->json(['status' => true, 'data' => $voucher->fresh()]);
    }

    public function destroy(Voucher $voucher)
    {
        $voucher->update(['is_active' => false]);
        return response()->json(['status' => true]);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => 'required|string|max:50|unique:vouchers,code' . ($id ? ',' . $id : ''),
            'name' => 'required|string|max:150',
            'discount_type' => 'required|in:fixed,percentage',
            'discount_value' => 'required|numeric|min:0.01',
            'minimum_order_value' => 'required|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'max_uses' => 'required|integer|min:1',
            'max_uses_per_customer' => 'required|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
