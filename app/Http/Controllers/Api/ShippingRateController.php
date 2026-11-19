<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShippingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingRateController extends Controller
{
    public function show(ShippingService $shipping)
    {
        return response()->json(['data' => $shipping->settings()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'hub_name' => 'required|string|max:120', 'hub_address' => 'required|string|max:255',
            'hub_latitude' => 'required|numeric|between:8,24', 'hub_longitude' => 'required|numeric|between:102,110',
            'base_fee' => 'required|numeric|min:0|max:100000000|decimal:0,2',
            'included_weight_grams' => 'required|integer|min:0|max:1000000',
            'extra_kg_fee' => 'required|numeric|min:0|max:100000000|decimal:0,2',
            'included_distance_km' => 'required|numeric|min:0|lte:max_distance_km|decimal:0,2',
            'per_km_fee' => 'required|numeric|min:0|max:100000000|decimal:0,2',
            'free_shipping_threshold' => 'nullable|numeric|min:0.01|max:100000000|decimal:0,2',
            'max_distance_km' => 'required|numeric|min:0.1|max:3000|decimal:0,2',
        ]);
        DB::transaction(function () use ($data, $request) {
            $before = DB::table('shipping_rate_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless($before && (int) $before->version === $data['version'], 409, 'Biểu phí đã được người khác cập nhật. Hãy tải lại trước khi lưu.');
            DB::table('shipping_rate_settings')->where('id', 1)->update([...$data, 'version' => $before->version + 1, 'updated_at' => now()]);
            DB::table('admin_activity_logs')->insert([
                'actor_id' => $request->user()->id, 'action' => 'shipping_rates_updated',
                'subject_type' => 'shipping_rate_settings', 'subject_id' => 1,
                'details' => json_encode(['before' => $before, 'after' => $data], JSON_THROW_ON_ERROR),
            ]);
        }, 3);
        return $this->show(app(ShippingService::class));
    }

    public function search(Request $request, ShippingService $shipping)
    {
        $data = $request->validate(['query' => 'required|string|min:3|max:255']);
        return response()->json(['data' => $shipping->search($data['query'])]);
    }
}
