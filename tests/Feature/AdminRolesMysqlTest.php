<?php

namespace Tests\Feature;

use App\Models\Account\Admin\Admin;
use App\Models\Account\User\User;
use App\Models\Product\Product;
use App\Models\Shop\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRolesMysqlTest extends TestCase
{
    private bool $started = false;
    private array $accounts = [];
    private array $tokens = [];
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') $this->markTestSkipped('Requires migrated MySQL.');
        DB::beginTransaction();
        $this->started = true;
        $this->password = Str::random(24);
        foreach (['super_admin', 'logistics_admin', 'commerce_admin'] as $code) {
            $name = 'roles_' . Str::random(12);
            $this->accounts[$code] = Admin::forceCreate(['username' => $name, 'email' => $name . '@example.test',
                'password' => bcrypt($this->password), 'role_id' => DB::table('administrator_roles')->where('code', $code)->value('id')]);
            $this->tokens[$code] = $this->accounts[$code]->createToken('roles-test')->plainTextToken;
        }
    }

    protected function tearDown(): void
    {
        if ($this->started) DB::rollBack();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_the_three_roles_enforce_the_complete_read_access_matrix(): void
    {
        $paths = ['me' => 'all', 'overview' => 'all', 'roles' => 'super_admin', 'administrators' => 'super_admin', 'activity' => 'super_admin',
            'shipping/providers' => 'logistics_admin', 'shipping/staff' => 'logistics_admin', 'shipments' => 'logistics_admin', 'shipments/dashboard' => 'logistics_admin',
            'customers' => 'commerce_admin', 'sellers' => 'commerce_admin', 'seller-applications' => 'commerce_admin', 'products' => 'commerce_admin',
            'orders' => 'commerce_admin', 'payments' => 'commerce_admin', 'vouchers' => 'commerce_admin', 'reports/monthly-revenue' => 'commerce_admin'];
        foreach (array_keys($this->accounts) as $role) {
            foreach ($paths as $path => $required) {
                $response = $this->api('GET', '/' . $path, $role);
                $response->assertStatus($required === 'all' || $role === 'super_admin' || $role === $required ? 200 : 403);
            }
        }
        $this->api('GET', '/overview', 'logistics_admin')->assertJsonMissingPath('data.counts.users')->assertJsonMissingPath('data.counts.orders');
        $this->api('GET', '/dashboard', 'logistics_admin')->assertForbidden();
        $this->api('GET', '/overview', 'commerce_admin')->assertJsonMissingPath('data.counts.administrators')->assertJsonMissingPath('data.counts.delivery_staff');
    }

    public function test_super_admin_creates_accounts_and_client_payload_cannot_add_permissions(): void
    {
        $name = 'created_' . Str::random(10);
        $data = ['username' => $name, 'email' => $name . '@example.test', 'role_code' => 'commerce_admin', 'is_active' => true,
            'password' => $this->password, 'password_confirmation' => $this->password, 'permissions' => ['super'], 'role_id' => 1];
        $this->api('POST', '/administrators', 'logistics_admin', $data)->assertForbidden();
        $this->api('POST', '/administrators', 'commerce_admin', $data)->assertForbidden();
        $response = $this->api('POST', '/administrators', 'super_admin', $data)->assertCreated()
            ->assertJsonPath('data.role.code', 'commerce_admin')->assertJsonPath('data.permissions', ['commerce'])->assertJsonMissingPath('data.password');
        $this->assertSame(1, DB::table('admin_activity_logs')->where('subject_id', $response->json('data.id'))->where('action', 'admin_created')->count());
        $log = DB::table('admin_activity_logs')->where('subject_id', $response->json('data.id'))->first();
        $this->assertStringNotContainsString($this->password, $log->details);
        $this->api('POST', '/administrators', 'super_admin', [...$data, 'role_code' => 'payment_admin'])->assertUnprocessable();
    }

    public function test_role_changes_revoke_tokens_and_new_login_gets_only_the_new_permissions(): void
    {
        $target = $this->accounts['commerce_admin'];
        $this->api('PUT', '/administrators/' . $target->id, 'super_admin', ['username' => $target->username, 'email' => $target->email,
            'role_code' => 'logistics_admin', 'is_active' => true])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->api('GET', '/customers', 'commerce_admin')->assertUnauthorized();
        Auth::forgetGuards();
        $login = $this->postJson('/api/v2/admin/auth/sign-in', ['usernameOrEmail' => $target->username, 'password' => $this->password])
            ->assertCreated()->assertJsonPath('data.permissions', ['logistics'])->assertJsonMissingPath('data.password');
        $this->tokens['commerce_admin'] = $login->json('token');
        $this->api('GET', '/shipping/providers', 'commerce_admin')->assertOk();
        $this->api('GET', '/customers', 'commerce_admin')->assertForbidden();
    }

    public function test_self_lockout_and_inactive_admin_login_are_blocked(): void
    {
        $self = $this->accounts['super_admin'];
        $body = ['username' => $self->username, 'email' => $self->email, 'role_code' => 'super_admin', 'is_active' => false];
        $this->api('PUT', '/administrators/' . $self->id, 'super_admin', $body)->assertUnprocessable();
        $this->api('PUT', '/administrators/' . $self->id, 'super_admin', [...$body, 'is_active' => true, 'role_code' => 'commerce_admin'])->assertUnprocessable();
        $target = $this->accounts['commerce_admin'];
        $this->api('PUT', '/administrators/' . $target->id, 'super_admin', ['username' => $target->username, 'email' => $target->email,
            'role_code' => 'commerce_admin', 'is_active' => false])->assertOk();
        $this->api('GET', '/me', 'commerce_admin')->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/api/v2/admin/auth/sign-in', ['usernameOrEmail' => $target->username, 'password' => $this->password])->assertForbidden();
    }

    public function test_write_endpoints_are_scoped_and_product_moderation_requires_a_reason(): void
    {
        $name = 'seller_' . Str::random(10);
        $seller = User::create(['username' => $name, 'email' => $name . '@example.test']);
        Shop::create(['id' => $seller->id, 'name' => 'Role test shop', 'slug' => Str::uuid()->toString()]);
        $product = Product::create(['name' => 'Role test product', 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 1]);
        $this->api('PATCH', '/products/' . $product->id . '/moderation', 'logistics_admin', ['status_id' => 2, 'note' => 'Vi phạm nội dung.'])->assertForbidden();
        $this->api('PATCH', '/products/' . $product->id . '/moderation', 'commerce_admin', ['status_id' => 2])->assertUnprocessable();
        $this->api('PATCH', '/products/' . $product->id . '/moderation', 'commerce_admin', ['status_id' => 2, 'note' => 'Vi phạm nội dung.'])->assertOk();
        $this->assertSame(2, (int) $product->fresh()->status_id);
        $this->api('POST', '/shipping/providers', 'commerce_admin', ['code' => 'NO', 'name' => 'Không được tạo', 'is_active' => true])->assertForbidden();
        $this->api('PUT', '/customers/' . $seller->id, 'logistics_admin', ['account_status' => 'blocked'])->assertForbidden();
        $token = $seller->createToken('not-an-admin')->plainTextToken;
        Auth::forgetGuards();
        $this->getJson('/api/v2/admin/administrators', ['Authorization' => 'Bearer ' . $token])->assertUnauthorized();
    }

    private function api(string $method, string $path, string $role, array $data = [])
    {
        Auth::forgetGuards();
        return $this->json($method, '/api/v2/admin' . $path, $data, ['Authorization' => 'Bearer ' . $this->tokens[$role]]);
    }
}
