<?php

namespace Tests\Feature;

use App\Models\Account\User\User;
use App\Models\Promotion\Voucher;
use Database\Seeders\November2026PromotionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionMysqlTest extends TestCase
{
    public function test_saving_vouchers_requires_authentication(): void
    {
        $this->postJson('/api/v2/user/account/vouchers/1/save')->assertUnauthorized();
    }

    public function test_campaigns_are_public_scoped_and_seeded_idempotently(): void
    {
        $this->withDatabase(function () {
            $this->seed(November2026PromotionSeeder::class);
            $voucher = Voucher::where('code', 'SIEUSALE1111')->firstOrFail();
            $voucher->update(['used_count' => 7]);
            $this->seed(November2026PromotionSeeder::class);
            $this->assertSame(7, (int) $voucher->fresh()->used_count);
            $this->assertSame(1, Voucher::where('code', 'SIEUSALE1111')->count());
            $this->getJson('/api/v2/promotions/sale-11-11')->assertOk()
                ->assertJsonCount(3, 'data.vouchers')->assertJsonPath('data.slug', 'sale-11-11');
            $this->getJson('/api/v2/promotions/featured-2026')->assertOk()
                ->assertJsonCount(2, 'data.vouchers');
            foreach (['best-sellers' => 2, 'tech-deals' => 3, 'everyday-picks' => 2] as $slug => $count) {
                $this->getJson('/api/v2/promotions/' . $slug)->assertOk()
                    ->assertJsonPath('data.slug', $slug)->assertJsonCount($count, 'data.vouchers');
            }
            $voucher->update(['is_active' => false]);
            $this->getJson('/api/v2/promotions/sale-11-11')->assertOk()->assertJsonCount(2, 'data.vouchers');
            $this->getJson('/api/v2/promotions/missing')->assertNotFound();
            $this->assertSame('2026-11-30 16:59:59', $voucher->ends_at->utc()->format('Y-m-d H:i:s'));
        });
    }

    public function test_saves_are_persistent_personal_and_do_not_consume_usage(): void
    {
        $this->withDatabase(function () {
            $user = $this->account();
            $voucher = $this->voucher();
            $headers = ['Authorization' => 'Bearer ' . $user->createToken('promotion-test')->plainTextToken];
            for ($i = 0; $i < 2; $i++) {
                Auth::forgetGuards();
                $this->postJson('/api/v2/user/account/vouchers/' . $voucher->id . '/save', [], $headers)
                    ->assertOk()->assertJsonPath('data.saved', true);
            }
            $this->assertSame(1, DB::table('voucher_saves')->where('voucher_id', $voucher->id)->count());
            $this->assertSame(0, (int) $voucher->fresh()->used_count);
            Auth::forgetGuards();
            $response = $this->getJson('/api/v2/user/account/vouchers', $headers)->assertOk();
            $record = collect($response->json('data'))->firstWhere('id', $voucher->id);
            $this->assertNotNull($record['saved_at']);

            $other = $this->account();
            Auth::forgetGuards();
            $response = $this->getJson('/api/v2/user/account/vouchers', [
                'Authorization' => 'Bearer ' . $other->createToken('promotion-test')->plainTextToken,
            ])->assertOk();
            $record = collect($response->json('data'))->firstWhere('id', $voucher->id);
            $this->assertNull($record['saved_at']);
        });
    }

    public function test_inactive_expired_future_and_exhausted_vouchers_cannot_be_saved(): void
    {
        $this->withDatabase(function () {
            $user = $this->account();
            $headers = ['Authorization' => 'Bearer ' . $user->createToken('promotion-test')->plainTextToken];
            foreach ([
                ['is_active' => false],
                ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()],
                ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)],
                ['max_uses' => 1, 'used_count' => 1],
            ] as $attributes) {
                $voucher = $this->voucher($attributes);
                Auth::forgetGuards();
                $this->postJson('/api/v2/user/account/vouchers/' . $voucher->id . '/save', [], $headers)
                    ->assertUnprocessable();
                $this->assertDatabaseMissing('voucher_saves', ['voucher_id' => $voucher->id, 'user_id' => $user->id]);
            }
        });
    }

    private function withDatabase(callable $test): void
    {
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the migrated local MySQL test environment.');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'UTC'));
        DB::beginTransaction();
        try {
            $test();
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
            Auth::forgetGuards();
        }
    }

    private function account(): User
    {
        $name = 'promotion_' . Str::random(10);
        return User::create(['username' => $name, 'email' => $name . '@example.test']);
    }

    private function voucher(array $attributes = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'TEST-' . Str::upper(Str::random(12)), 'name' => 'Promotion test',
            'discount_type' => 'percentage', 'discount_value' => 10, 'minimum_order_value' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'max_uses' => 10, 'max_uses_per_customer' => 1, 'is_active' => true,
        ], $attributes));
    }
}
