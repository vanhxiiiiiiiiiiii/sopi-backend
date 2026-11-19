<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account\User\User;
use App\Models\Shop\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerApplicationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function mine(Request $request)
    {
        return response()->json(['data' => [
            'shop' => $request->user()->shop,
            'application' => DB::table('seller_applications')->where('user_id', $request->user()->id)->first(),
        ]]);
    }

    public function submit(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'phone' => 'required|digits_between:10,11',
            'email' => 'required|email|max:255', 'address' => 'required|string|max:1000',
            'tax_code' => 'nullable|string|max:50',
        ]);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($user->shop, 409, 'Tài khoản đã có cửa hàng.');
            $existing = DB::table('seller_applications')->where('user_id', $user->id)->first();
            abort_if($existing && $existing->status !== 'rejected', 409, 'Hồ sơ đang được xử lý.');
            DB::table('seller_applications')->updateOrInsert(['user_id' => $user->id], [
                ...$data, 'status' => 'pending', 'review_note' => null, 'reviewed_by' => null,
                'reviewed_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->mine($request);
    }

    public function index()
    {
        return response()->json(['data' => DB::table('seller_applications')->orderByDesc('updated_at')->get()]);
    }

    public function dashboard(Request $request)
    {
        $id = $request->user()->id;
        $orders = DB::table('orders')->where('shop_id', $id);
        return response()->json(['data' => [
            'counts' => (clone $orders)->selectRaw('status_id, COUNT(*) total')->groupBy('status_id')->pluck('total', 'status_id'),
            'revenue' => (clone $orders)->where('status_id', 4)->sum('payment_total'),
            'products' => DB::table('products')->where('shop_id', $id)->count(),
            'recent' => (clone $orders)->orderByDesc('id')->limit(8)->get(['id', 'order_code', 'name', 'status_id', 'payment_total', 'created_at']),
        ]]);
    }

    public function review(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected', 'review_note' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($request, $id, $data) {
            $application = DB::table('seller_applications')->find($id);
            abort_unless($application, 404);
            $user = User::whereKey($application->user_id)->lockForUpdate()->firstOrFail();
            $application = DB::table('seller_applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($application->status === 'pending', 409, 'Hồ sơ đã được xử lý.');
            if ($data['status'] === 'approved') {
                abort_if($user->shop, 409, 'Tài khoản đã có cửa hàng.');
                Shop::create(['id' => $user->id, 'seller_code' => 'SELLER-' . $user->id,
                    'name' => $application->name, 'slug' => 'shop-' . $user->id . '-' . Str::lower(Str::random(8)),
                    'phone' => $application->phone, 'email' => $application->email,
                    'tax_code' => $application->tax_code, 'is_active' => true]);
            }
            DB::table('seller_applications')->where('id', $id)->update([
                ...$data, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
        });
        return response()->json(['status' => true]);
    }
}
