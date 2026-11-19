<?php

namespace Tests\Feature;

use App\Models\Account\Admin\Admin;
use App\Models\Account\User\User;
use App\Models\Address;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Warehouse;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Shop\Shop;
use App\Services\OrderService;
use App\Http\Controllers\Api\Auth\SignUpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BusinessFlowRegressionMysqlTest extends TestCase
{
    private bool $transactionStarted = false;
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the migrated local MySQL test environment.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $buyer = $this->account();
        $seller = $this->account();
        Shop::create(['id' => $seller->id, 'name' => 'Business regression shop', 'slug' => Str::uuid()->toString()]);
        $shop = Shop::findOrFail($seller->id);
        $address = Address::create(['full_name' => 'Test recipient', 'phone' => '0901234567',
            'address' => 'Test street', 'town' => 'Ward', 'city' => 'District', 'state' => 'Province']);
        $buyer->addresses()->attach($address);
        $product = Product::create(['name' => 'Regression ' . Str::random(12), 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 100]);
        $model = $product->models()->create(['price' => 12.5, 'stock' => 10, 'variation_index' => []]);
        $admin = Admin::forceCreate(['role_id' => DB::table('administrator_roles')->where('code', 'super_admin')->value('id'),
            'username' => 'regression_' . Str::random(12), 'email' => Str::random(12) . '@example.test', 'password' => bcrypt(Str::random(25))]);
        $this->f = compact('buyer', 'seller', 'shop', 'address', 'product', 'model', 'admin');
        foreach (['buyer', 'seller', 'admin'] as $key) {
            $this->f[$key . 'Token'] = $this->f[$key]->createToken('regression')->plainTextToken;
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_blocked_and_inactive_customers_cannot_login_or_use_existing_tokens(): void
    {
        $password = Str::random(25);
        foreach (['blocked', 'inactive'] as $status) {
            $this->f['buyer']->update(['account_status' => $status, 'password' => bcrypt($password)]);
            $this->api('POST', '/user/auth/sign-in', 'buyer', ['usernameOrEmail' => $this->f['buyer']->username, 'password' => $password])->assertForbidden();
            $this->api('GET', '/user/cart', 'buyer')->assertForbidden();
            $this->api('POST', '/user/orders', 'buyer', $this->payload())->assertForbidden();
            $this->assertSame(1, $this->f['buyer']->tokens()->count());
        }
        $this->assertSame(0, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->assertSame(10, (int) $this->f['model']->fresh()->stock);
    }

    public function test_admin_suspension_revokes_all_tokens_and_reactivation_requires_a_new_login(): void
    {
        $password = Str::random(25);
        $this->f['buyer']->update(['password' => bcrypt($password)]);
        $this->f['buyer']->createToken('second-device');
        $this->api('PUT', '/admin/customers/' . $this->f['buyer']->id, 'admin', ['account_status' => 'blocked'])->assertOk();
        $this->assertSame(0, $this->f['buyer']->tokens()->count());
        $this->api('GET', '/user/cart', 'buyer')->assertUnauthorized();
        $this->api('PUT', '/admin/customers/' . $this->f['buyer']->id, 'admin', ['account_status' => 'active'])->assertOk();
        $this->api('GET', '/user/cart', 'buyer')->assertUnauthorized();
        $login = $this->api('POST', '/user/auth/sign-in', 'buyer', ['usernameOrEmail' => $this->f['buyer']->username, 'password' => $password])->assertCreated();
        $this->f['buyerToken'] = $login->json('token');
        $this->api('GET', '/user/cart', 'buyer')->assertOk();
    }

    public function test_oauth_callback_does_not_issue_a_token_for_a_blocked_existing_customer(): void
    {
        $this->f['buyer']->update(['account_status' => 'blocked']);
        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->once()->andReturn((new OAuthUser())->map(['email' => $this->f['buyer']->email]));
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
        try {
            app(SignUpController::class)->OAuthCallback(Request::create('/oauth/google/callback'), 'google');
            $this->fail('OAuth must not create a token for a suspended customer.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(1, $this->f['buyer']->tokens()->count());
    }

    public function test_checkout_rechecks_customer_status_inside_the_transaction(): void
    {
        $this->addCart();
        $this->f['buyer']->update(['account_status' => 'blocked']);
        try {
            app(OrderService::class)->create($this->f['buyer']->id, $this->payload());
            $this->fail('Checkout must reject a suspended customer even when invoked without HTTP middleware.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(10, (int) $this->f['model']->fresh()->stock);
        $this->assertSame(0, Order::where('user_id', $this->f['buyer']->id)->count());
    }

    public function test_suspending_a_shop_invalidates_existing_cart_quote_and_checkout_without_side_effects(): void
    {
        $this->addCart();
        $this->api('POST', '/user/orders/quote', 'buyer')->assertOk();
        $this->api('PUT', '/admin/sellers/' . $this->f['seller']->id, 'admin', ['is_active' => false])->assertOk();
        $this->api('GET', '/user/cart', 'buyer')->assertOk()->assertJsonPath('data.0.unavailable_reason', 'Cửa hàng đã ngừng hoạt động.');
        $this->api('POST', '/user/orders/quote', 'buyer')->assertUnprocessable();
        $this->api('POST', '/user/orders', 'buyer', $this->payload())->assertUnprocessable();
        $this->api('POST', '/user/cart', 'buyer', $this->item())->assertUnprocessable();
        $this->assertSame(10, (int) $this->f['model']->fresh()->stock);
        $this->assertSame(0, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->assertSame(0, DB::table('checkout_receipts')->where('user_id', $this->f['buyer']->id)->count());
        $this->api('GET', '/user/cart', 'buyer')->assertJsonCount(1, 'data');
        $this->api('PUT', '/admin/sellers/' . $this->f['seller']->id, 'admin', ['is_active' => true])->assertOk();
        $this->api('POST', '/user/orders', 'buyer', $this->payload())->assertCreated();
    }

    public function test_inactive_shops_are_hidden_from_public_catalog_by_both_id_and_slug(): void
    {
        $id = $this->f['product']->id;
        $this->getJson('/api/v2/products/' . $id)->assertOk();
        $this->getJson('/api/v2/shops/' . $this->f['seller']->id)->assertOk();
        $this->getJson('/api/v2/shops/' . $this->f['shop']->slug)->assertOk();
        $active = $this->getJson('/api/v2/products/search?keyword=' . rawurlencode($this->f['product']->name))->assertOk();
        $this->assertContains($id, array_column($active->json('data.data'), 'id'));
        $this->f['shop']->update(['is_active' => false]);
        $this->getJson('/api/v2/products/' . $id)->assertNotFound();
        $this->getJson('/api/v2/shops/' . $this->f['seller']->id)->assertNotFound();
        $this->getJson('/api/v2/shops/' . $this->f['shop']->slug)->assertNotFound();
        $this->getJson('/api/v2/shops/' . $this->f['seller']->id . '/products')->assertOk()->assertJsonCount(0, 'data.data');
        $response = $this->getJson('/api/v2/products/search?keyword=' . rawurlencode($this->f['product']->name))->assertOk();
        $this->assertNotContains($id, array_column($response->json('data.data'), 'id'));
        $response = $this->getJson('/api/v2/products/feature?limit=100')->assertOk();
        $this->assertNotContains($id, array_column($response->json('data.data'), 'id'));
    }

    public function test_first_import_preserves_opening_stock_and_never_duplicates_it_in_other_warehouses(): void
    {
        $warehouse = $this->warehouse();
        $this->adjust($warehouse, 5)->assertOk()->assertJsonPath('data.quantity', 15);
        $this->assertStock(15, 15);
        $this->adjust($warehouse, -3)->assertOk()->assertJsonPath('data.quantity', 12);
        $this->assertStock(12, 12);
        $other = $this->warehouse();
        $this->adjust($other, 2)->assertOk()->assertJsonPath('data.quantity', 2);
        $this->assertStock(14, 14);
        $this->assertSame(1, InventoryMovement::where('product_model_id', $this->f['model']->id)->where('movement_type', 'opening_balance')->count());
        $this->assertEquals(14, InventoryMovement::where('product_model_id', $this->f['model']->id)->sum('quantity_change'));
        $this->api('POST', '/user/cart', 'buyer', $this->item(6))->assertOk();
        $payload = $this->payload();
        $payload['orders'] = [$this->item(6)];
        $id = $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->json('data.0.id');
        $this->assertStock(8, 8);
        $this->api('POST', '/user/orders/' . $id . '/cancel', 'buyer')->assertOk();
        $this->assertStock(14, 14);
    }

    public function test_checkout_then_import_does_not_reallocate_an_opening_balance(): void
    {
        $this->addCart();
        $this->api('POST', '/user/orders', 'buyer', $this->payload())->assertCreated();
        $this->assertStock(8, 8);
        $warehouse = Warehouse::where('shop_id', $this->f['seller']->id)->firstOrFail();
        $this->adjust($warehouse, 5)->assertOk();
        $this->assertStock(13, 13);
        $this->assertSame(1, InventoryMovement::where('product_model_id', $this->f['model']->id)->where('movement_type', 'opening_balance')->count());
    }

    public function test_invalid_first_adjustment_rolls_back_initial_allocation(): void
    {
        $warehouse = $this->warehouse();
        $this->adjust($warehouse, -11)->assertUnprocessable();
        $this->assertSame(0, $this->f['model']->inventoryStocks()->count());
        $this->assertSame(0, InventoryMovement::where('product_model_id', $this->f['model']->id)->count());
        $this->assertStock(10, 10);
        $warehouse->update(['is_active' => false]);
        $this->adjust($warehouse, 5)->assertUnprocessable();
        $this->assertStock(10, 10);
    }

    private function assertStock(int $total, int $available): void
    {
        $model = $this->f['model']->fresh();
        $this->assertSame($total, (int) $model->stock);
        $this->assertSame($available, $model->availableQuantity());
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create(['warehouse_code' => 'REG-' . Str::random(12), 'shop_id' => $this->f['seller']->id,
            'name' => 'Regression warehouse', 'address' => 'Test street', 'is_active' => true]);
    }

    private function adjust(Warehouse $warehouse, int $quantity)
    {
        return $this->api('POST', '/shop/inventory/adjust', 'seller', ['warehouse_id' => $warehouse->id,
            'product_model_id' => $this->f['model']->id, 'quantity_change' => $quantity, 'movement_type' => 'adjustment']);
    }

    private function addCart(): void { $this->api('POST', '/user/cart', 'buyer', $this->item())->assertOk(); }
    private function item(int $quantity = 2): array { return ['product_model_id' => $this->f['model']->id, 'quantity' => $quantity]; }
    private function payload(): array { return ['request_key' => Str::uuid()->toString(), 'address_id' => $this->f['address']->id, 'payment_method' => 'cod', 'orders' => [$this->item()]]; }
    private function account(): User { $name = 'regression_' . Str::random(12); return User::create(['username' => $name, 'email' => $name . '@example.test']); }

    private function api(string $method, string $path, string $actor, array $data = [])
    {
        Auth::forgetGuards();
        return $this->json($method, '/api/v2' . $path, $data, ['Authorization' => 'Bearer ' . $this->f[$actor . 'Token']]);
    }
}
