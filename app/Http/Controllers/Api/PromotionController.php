<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionController extends Controller
{
    public function show(string $campaign)
    {
        $promotion = config('promotions.' . $campaign);
        abort_unless($promotion, 404);

        $codes = $promotion['voucher_codes'] ?? array_column($promotion['vouchers'], 'code');
        $vouchers = Voucher::whereIn('code', $codes)
            ->where('is_active', true)->orderBy('id')->get();

        return response()->json(['status' => true, 'data' => [
            'slug' => $campaign, 'name' => $promotion['name'], 'vouchers' => $vouchers,
        ]]);
    }

    public function save(Request $request, Voucher $voucher)
    {
        return DB::transaction(function () use ($request, $voucher) {
            $voucher = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $used = DB::table('voucher_usages')->where('voucher_id', $voucher->id)
                ->where('user_id', $request->user()->id)->count();
            if (!$voucher->is_active || now()->lt($voucher->starts_at) || now()->gt($voucher->ends_at)
                || $voucher->used_count >= $voucher->max_uses || $used >= $voucher->max_uses_per_customer) {
                throw ValidationException::withMessages(['voucher' => 'Voucher chưa có hiệu lực, đã hết hạn hoặc hết lượt sử dụng.']);
            }

            DB::table('voucher_saves')->insertOrIgnore([
                'voucher_id' => $voucher->id, 'user_id' => $request->user()->id, 'saved_at' => now(),
            ]);

            return response()->json(['status' => true, 'data' => ['voucher_id' => $voucher->id, 'saved' => true]]);
        });
    }
}
