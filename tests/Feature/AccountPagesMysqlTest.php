<?php

namespace Tests\Feature;

use App\Models\Account\User\User;
use App\Models\Promotion\Voucher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountPagesMysqlTest extends TestCase
{
    public function test_customer_vouchers_require_authentication(): void
    {
        $this->getJson('/api/v2/user/account/vouchers')->assertUnauthorized();
    }

    public function test_profile_birth_date_and_customer_vouchers_support_account_pages(): void
    {
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the migrated local MySQL test environment.');
        }

        DB::beginTransaction();
        try {
            $name = 'account_' . Str::random(12);
            $user = User::create(['username' => $name, 'email' => $name . '@example.test']);
            $token = $user->createToken('account-ui-test')->plainTextToken;
            $headers = ['Authorization' => 'Bearer ' . $token];
            Auth::forgetGuards();
            $this->json('PUT', '/api/v2/user/account/profile', [
                'display_name' => 'Account Test',
                'date_of_birth' => '12/31/2000',
            ], $headers)->assertOk();
            $this->assertDatabaseHas('user_profiles', [
                'user_id' => $user->id,
                'date_of_birth' => '2000-12-31',
            ]);
            Auth::forgetGuards();
            $this->json('GET', '/api/v2/user/account/profile', [], $headers)
                ->assertOk()->assertJsonPath('data.profile.date_of_birth', '12/31/2000');

            $voucher = Voucher::create([
                'code' => 'ACCOUNT-' . Str::upper(Str::random(12)),
                'name' => 'Account voucher test',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'minimum_order_value' => 0,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDay(),
                'max_uses' => 10,
                'max_uses_per_customer' => 1,
                'is_active' => true,
            ]);
            Auth::forgetGuards();
            $response = $this->json('GET', '/api/v2/user/account/vouchers', [], $headers)->assertOk();
            $record = collect($response->json('data'))->firstWhere('id', $voucher->id);
            $this->assertNotNull($record);
            $this->assertSame(0, (int) $record['customer_used_count']);
            $this->assertSame($voucher->code, $record['code']);
        } finally {
            DB::rollBack();
        }
    }
}
