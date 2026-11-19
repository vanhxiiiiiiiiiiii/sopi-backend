<?php

namespace Database\Seeders;

use App\Models\Promotion\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class November2026PromotionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('promotions') as $campaign) {
            foreach ($campaign['vouchers'] ?? [] as $voucher) {
                // Re-running must preserve usage counters and administrator edits.
                Voucher::firstOrCreate(['code' => $voucher['code']], array_merge($voucher, [
                    'starts_at' => CarbonImmutable::parse('2026-11-01 00:00:00', 'Asia/Ho_Chi_Minh')->utc(),
                    'ends_at' => CarbonImmutable::parse('2026-11-30 23:59:59', 'Asia/Ho_Chi_Minh')->utc(),
                    'max_uses_per_customer' => 1,
                    'is_active' => true,
                ]));
            }
        }
    }
}
