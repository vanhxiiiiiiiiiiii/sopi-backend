<?php

namespace Tests\Feature;

use App\Models\Account\Admin\Admin;
use App\Models\Account\User\User;
use App\Models\Address;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Shop\Shop;
use App\Services\ShippingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingRatesMysqlTest extends TestCase
{
    use \Tests\Concerns\UsesShippingRoutes;

    private bool $started = false;
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') $this->markTestSkipped('Requires migrated MySQL.');
        DB::beginTransaction();
        $this->started = true;
        $this->fakeShippingRoutes();
        $buyer = $this->user(); $seller = $this->user();
        Shop::create(['id' => $seller->id, 'name' => 'Shipping fixture', 'slug' => Str::uuid()->toString()]);
        $address = Address::create(['full_name' => 'Shipping recipient', 'phone' => '0901234567',
            'address' => 'Test street', 'town' => 'Ward', 'city' => 'District', 'state' => 'Hà Nội', 'latitude' => 20.99, 'longitude' => 105.8]);
        $buyer->addresses()->attach($address); $seller->addresses()->attach($address);
        $product = Product::create(['name' => 'Shipping product', 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 700]);
        $model = $product->models()->create(['price' => 10, 'stock' => 30, 'variation_index' => []]);
        $this->f = compact('buyer', 'seller', 'address', 'product', 'model');
        foreach (['buyer', 'seller'] as $key) $this->f[$key . 'Token'] = $this->f[$key]->createToken('shipping-test')->plainTextToken;
        foreach (['super_admin', 'logistics_admin', 'commerce_admin'] as $code) {
            $name = 'shipping_' . Str::random(12);
            $admin = Admin::forceCreate(['username' => $name, 'email' => $name . '@example.test', 'password' => bcrypt(Str::random(24)),
                'role_id' => DB::table('administrator_roles')->where('code', $code)->value('id')]);
            $this->f[$code . 'Token'] = $admin->createToken('shipping-test')->plainTextToken;
        }
    }

    protected function tearDown(): void
    {
        if ($this->started) DB::rollBack();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_weight_distance_and_free_threshold_boundaries(): void
    {
        $service = app(ShippingService::class);
        $settings = $service->settings();
        $route = ['distance_km' => 3, 'source' => 'OSRM'];
        $this->assertSame(2.0, $service->fee(99.99, 1000, $route, $settings)['fee']);
        $this->assertSame(3.0, $service->fee(99.99, 1000.1, $route, $settings)['fee']);
        $this->assertSame(4.0, $service->fee(99.99, 2000.1, $route, $settings)['fee']);
        $this->assertSame(2.01, $service->fee(99.99, 1000, ['distance_km' => 3.1, 'source' => 'OSRM'], $settings)['fee']);
        $this->assertSame(0.0, $service->fee(100, 10000, $route, $settings)['fee']);
        $this->assertSame(11.0, $service->fee(100, 10000, $route, [...$settings, 'free_shipping_threshold' => null])['fee']);
    }

    public function test_quote_checkout_shipment_and_cod_use_the_same_server_calculated_fee(): void
    {
        $payload = $this->payload(3);
        $quote = $this->quote()->assertOk()->assertJsonPath('data.shipping_fee', 4.7)
            ->assertJsonPath('data.payment_total', 34.7)->assertJsonPath('data.shipping_details.0.weight_grams', 2100)
            ->assertJsonPath('data.shipping_details.0.distance_km', 10)->json('data');
        $payload += ['shipping_fee' => 0, 'distance_km' => 0, 'weight_grams' => 0, 'expected_payment_total' => $quote['payment_total']];
        $order = $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()
            ->assertJsonPath('data.0.shipping_snapshot.extra_kg', 2)->json('data.0');
        $this->assertEquals(4.7, $order['shipping_fee']);
        $this->assertEquals(34.7, $order['payments'][0]['amount']);
        $this->assertEquals(1.5, $order['commission_amount']);
        DB::table('shipping_rate_settings')->where('id', 1)->update(['base_fee' => 50]);
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->assertJsonPath('data.0.id', $order['id']);
        $this->assertSame(27, (int) $this->f['model']->fresh()->stock);
        foreach (['confirm', 'prepare'] as $step) $this->api('POST', '/shop/orders/' . $order['id'] . '/' . $step, 'seller')->assertOk();
        $this->api('POST', '/shop/orders/' . $order['id'] . '/ready', 'seller', ['pickup_address_id' => $this->f['address']->id])->assertOk();
        $this->assertEquals(4.7, Order::findOrFail($order['id'])->shipment->shipping_fee);
        $this->assertEquals(4.7, Order::findOrFail($order['id'])->shipping_snapshot['fee']);
        Http::assertSentCount(1); // Quote + checkout use a cached real-route result.
    }

    public function test_multiple_shops_are_charged_separately_and_free_shipping_is_per_shop(): void
    {
        $payload = $this->payload(10); // First shop: 100, qualifies for free shipping.
        $seller = $this->user();
        Shop::create(['id' => $seller->id, 'name' => 'Second shop', 'slug' => Str::uuid()->toString()]);
        $product = Product::create(['name' => 'Second product', 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 100]);
        $model = $product->models()->create(['price' => 10, 'stock' => 5, 'variation_index' => []]);
        $this->api('POST', '/user/cart', 'buyer', ['product_model_id' => $model->id, 'quantity' => 1])->assertOk();
        $payload['orders'][] = ['product_model_id' => $model->id, 'quantity' => 1];
        $this->quote()->assertOk()->assertJsonPath('data.shipping_fee', 2.7)->assertJsonPath('data.payment_total', 112.7)
            ->assertJsonPath('data.shipping_details.0.free_shipping', true)->assertJsonPath('data.shipping_details.1.free_shipping', false);
        $this->quote(['model_ids' => [$model->id]])->assertOk()->assertJsonPath('data.payment_total', 12.7)->assertJsonCount(1, 'data.shipping_details');
        $orders = $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->assertJsonCount(2, 'data')->json('data');
        $this->assertEquals(112.7, collect($orders)->sum('payment_total'));
    }

    public function test_missing_or_foreign_address_is_rejected_without_creating_orders(): void
    {
        $payload = $this->payload();
        $this->f['address']->update(['latitude' => null, 'longitude' => null]);
        $this->quote()->assertUnprocessable();
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertUnprocessable();
        $this->api('POST', '/user/orders/quote', 'super_admin', ['address_id' => $this->f['address']->id])->assertUnauthorized();
        $foreign = Address::create(['full_name' => 'Foreign', 'phone' => '0901234567', 'address' => 'Street',
            'town' => 'Ward', 'city' => 'City', 'state' => 'State', 'latitude' => 20.99, 'longitude' => 105.8]);
        $this->quote(['address_id' => $foreign->id])->assertUnprocessable();
        $this->assertNoCheckout();
        Http::assertNothingSent();
    }

    public function test_map_failure_never_creates_a_zero_fee_order(): void
    {
        $payload = $this->payload();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://routing.example.test/*' => Http::response([], 503)]);
        $this->quote()->assertStatus(503);
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertStatus(503);
        $this->assertNoCheckout();
    }

    public function test_no_route_or_outside_delivery_range_is_rejected_even_when_shipping_is_free(): void
    {
        $payload = $this->payload(10);
        DB::table('shipping_rate_settings')->where('id', 1)->update(['max_distance_km' => 5]);
        $this->quote()->assertUnprocessable();
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertUnprocessable();
        $this->assertNoCheckout();
    }

    public function test_changed_rates_require_buyer_to_review_the_new_total_before_ordering(): void
    {
        $payload = $this->payload();
        $total = $this->quote()->assertOk()->json('data.payment_total');
        DB::table('shipping_rate_settings')->where('id', 1)->update(['base_fee' => 8]);
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'expected_payment_total' => $total])->assertUnprocessable();
        $this->assertNoCheckout();
        $newTotal = $this->quote()->assertOk()->json('data.payment_total');
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'expected_payment_total' => $newTotal])->assertCreated();
    }

    public function test_no_road_route_or_missing_product_weight_blocks_checkout(): void
    {
        $payload = $this->payload();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://routing.example.test/*' => Http::response(['code' => 'NoRoute', 'routes' => []])]);
        $this->quote()->assertUnprocessable();
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertUnprocessable();
        $this->assertNoCheckout();
        $this->f['product']->update(['weight' => 0]);
        $this->quote()->assertUnprocessable();
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertUnprocessable();
        $this->assertNoCheckout();
    }

    public function test_voucher_does_not_remove_a_free_shipping_entitlement(): void
    {
        $payload = $this->payload(10);
        $code = 'SHIPPING-' . Str::random(10);
        DB::table('vouchers')->insert([
            'code' => $code, 'name' => 'Shipping voucher fixture', 'discount_type' => 'fixed', 'discount_value' => 10,
            'minimum_order_value' => 0, 'maximum_discount' => null, 'max_uses' => 100,
            'max_uses_per_customer' => 1, 'used_count' => 0, 'is_active' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        $this->quote(['voucher_code' => $code])->assertOk()->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.payment_total', 90);
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'voucher_code' => $code])->assertCreated()
            ->assertJsonPath('data.0.shipping_snapshot.free_shipping', true);
    }

    public function test_only_shipping_admins_change_rates_and_updates_are_validated_versioned_and_audited(): void
    {
        $logCount = DB::table('admin_activity_logs')->where('action', 'shipping_rates_updated')->count();
        $data = $this->api('GET', '/admin/shipping/rates', 'logistics_admin')->assertOk()->json('data');
        $this->api('PUT', '/admin/shipping/rates', 'commerce_admin', $data)->assertForbidden();
        $this->api('PUT', '/admin/shipping/rates', 'buyer', $data)->assertUnauthorized();
        $this->api('PUT', '/admin/shipping/rates', 'logistics_admin', [...$data, 'base_fee' => -1])->assertUnprocessable();
        $this->api('PUT', '/admin/shipping/rates', 'logistics_admin', [...$data, 'included_distance_km' => 2000])->assertUnprocessable();
        $this->api('PUT', '/admin/shipping/rates', 'logistics_admin', [...$data, 'base_fee' => 4])->assertOk()
            ->assertJsonPath('data.version', $data['version'] + 1);
        $this->api('PUT', '/admin/shipping/rates', 'super_admin', $data)->assertConflict();
        $this->assertSame($logCount + 1, DB::table('admin_activity_logs')->where('action', 'shipping_rates_updated')->count());
    }

    public function test_location_changes_clear_old_coordinates_and_search_is_cached_and_globally_throttled(): void
    {
        $id = $this->f['address']->id;
        $this->api('PUT', '/user/account/addresses/' . $id, 'buyer', ['address' => 'A different street'])->assertOk();
        $this->assertNull($this->f['address']->fresh()->latitude);
        $this->api('PUT', '/user/account/addresses/' . $id, 'buyer', ['latitude' => 20.99, 'longitude' => 105.8])->assertOk();
        $this->assertEquals(20.99, $this->f['address']->fresh()->latitude);
        $this->api('PUT', '/user/account/addresses/' . $id, 'buyer', ['latitude' => 30, 'longitude' => 105.8])->assertStatus(400);
        Http::fake(['https://geocoding.example.test/*' => Http::response([['lat' => '20.99', 'lon' => '105.8', 'display_name' => 'Result']])]);
        $this->api('POST', '/user/shipping/search', 'buyer', ['query' => 'PTIT Ha Dong'])->assertOk()->assertJsonPath('data.0.latitude', 20.99);
        $this->api('POST', '/user/shipping/search', 'buyer', ['query' => 'PTIT Ha Dong'])->assertOk();
        $this->api('POST', '/user/shipping/search', 'buyer', ['query' => 'Other location'])->assertStatus(429);
        Http::assertSentCount(1);
    }

    private function user(): User
    {
        $name = 'shipping_' . Str::random(12);
        return User::create(['username' => $name, 'email' => $name . '@example.test']);
    }

    public function test_two_level_addresses_can_be_saved_and_used_for_checkout_without_district(): void
    {
        $address = $this->api('POST', '/user/account/addresses', 'buyer', [
            'full_name' => 'Two level recipient', 'phone' => '0901234567', 'address' => 'Test street',
            'town' => 'Phường Hà Đông', 'state' => 'Hà Nội', 'latitude' => 20.99, 'longitude' => 105.8,
        ])->assertCreated()->json('data');
        $this->assertNull(Address::findOrFail($address['id'])->city);
        $payload = $this->payload(1);
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'address_id' => $address['id']])->assertCreated()
            ->assertJsonPath('data.0.recipient_snapshot.town', 'Phường Hà Đông')
            ->assertJsonPath('data.0.recipient_snapshot.city', null);
    }

    public function test_pin_preview_uses_the_selected_coordinates_immediately_without_modifying_a_saved_address(): void
    {
        $this->payload(1);
        $this->fakeRoutesByDestination();
        $this->quote()->assertOk()->assertJsonPath('data.shipping_fee', 2.7);
        $this->quote(['delivery_location' => ['latitude' => 21.02, 'longitude' => 105.82]])->assertOk()
            ->assertJsonPath('data.shipping_fee', 3.7)->assertJsonPath('data.shipping_route.distance_km', 20);
        $this->assertEquals(20.99, $this->f['address']->fresh()->latitude);
        $addressCount = Address::count();
        $this->api('POST', '/user/orders/quote', 'buyer', ['delivery_location' => ['latitude' => 21.02, 'longitude' => 105.82]])
            ->assertOk()->assertJsonPath('data.payment_total', 13.7);
        $this->assertSame($addressCount, Address::count());
        $this->assertSame(0, Order::where('user_id', $this->f['buyer']->id)->count());
        $foreign = Address::create(['full_name' => 'Foreign', 'phone' => '0901234567', 'address' => 'Street', 'town' => 'Ward', 'state' => 'Hà Nội']);
        $this->quote(['address_id' => $foreign->id, 'delivery_location' => ['latitude' => 21.02, 'longitude' => 105.82]])->assertUnprocessable();
    }

    public function test_checkout_persists_the_selected_pin_and_idempotency_rejects_a_different_pin(): void
    {
        $payload = $this->payload(1);
        $this->fakeRoutesByDestination();
        $pin = ['latitude' => 21.02, 'longitude' => 105.82];
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'delivery_location' => $pin, 'expected_payment_total' => 12.7])->assertUnprocessable();
        $this->assertEquals(20.99, $this->f['address']->fresh()->latitude);
        $order = $this->api('POST', '/user/orders', 'buyer', [...$payload, 'delivery_location' => $pin, 'expected_payment_total' => 13.7])
            ->assertCreated()->assertJsonPath('data.0.shipping_snapshot.distance_km', 20)->json('data.0');
        $this->assertEquals(21.02, $this->f['address']->fresh()->latitude);
        $this->assertEquals(21.02, $order['recipient_snapshot']['latitude']);
        $this->assertEquals(3.7, $order['shipping_fee']);
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'delivery_location' => $pin])->assertCreated()->assertJsonPath('data.0.id', $order['id']);
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'delivery_location' => ['latitude' => 20.99, 'longitude' => 105.8]])->assertUnprocessable();
        $this->assertSame(1, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->assertSame(29, (int) $this->f['model']->fresh()->stock);
    }

    public function test_preview_rejects_incomplete_or_invalid_coordinates(): void
    {
        $this->payload(1);
        foreach ([[], ['latitude' => 21.02], ['latitude' => 30, 'longitude' => 105.82], ['latitude' => 'wrong', 'longitude' => 105.82]] as $pin) {
            $this->quote(['delivery_location' => $pin])->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    private function fakeRoutesByDestination(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://routing.example.test/*' => fn ($request) => Http::response([
            'code' => 'Ok', 'waypoints' => [['distance' => 1], ['distance' => 2]],
            'routes' => [['distance' => str_contains($request->url(), '105.8200000,21.0200000') ? 20000 : 10000,
                'geometry' => ['type' => 'LineString', 'coordinates' => [[105.7870861, 20.9809427], [105.82, 21.02]]]]],
        ])]);
    }

    public function test_geocoder_failure_uses_a_cached_vietnam_only_fallback(): void
    {
        Http::fake([
            'https://geocoding.example.test/*' => Http::response([], 503),
            'https://fallback.example.test/*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [105.7870861, 20.9809427]], 'properties' => ['countrycode' => 'VN',
                    'name' => 'PTIT', 'city' => 'Hà Nội', 'district' => 'Old district', 'country' => 'Việt Nam']],
                ['geometry' => ['coordinates' => [105.8, 20.99]], 'properties' => ['countrycode' => 'CN', 'name' => 'Foreign result']],
            ]]),
        ]);
        $response = $this->api('POST', '/user/shipping/search', 'buyer', ['query' => 'PTIT Ha Dong'])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.latitude', 20.9809427);
        $this->assertStringNotContainsString('Old district', $response->json('data.0.label'));
        $this->api('POST', '/user/shipping/search', 'buyer', ['query' => 'PTIT Ha Dong'])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_both_geocoders_failing_keeps_the_error_visible(): void
    {
        Http::fake(['https://geocoding.example.test/*' => Http::response([], 503), 'https://fallback.example.test/*' => Http::response([], 503)]);
        $this->api('POST', '/user/shipping/search', 'buyer', ['query' => 'PTIT Ha Dong'])->assertStatus(503);
        Http::assertSentCount(2);
    }

    private function payload(int $quantity = 3): array
    {
        $item = ['product_model_id' => $this->f['model']->id, 'quantity' => $quantity];
        $this->api('POST', '/user/cart', 'buyer', $item)->assertOk();
        return ['request_key' => Str::uuid()->toString(), 'address_id' => $this->f['address']->id, 'orders' => [$item], 'payment_method' => 'cod'];
    }

    private function quote(array $data = [])
    {
        return $this->api('POST', '/user/orders/quote', 'buyer', ['address_id' => $this->f['address']->id, ...$data]);
    }

    private function assertNoCheckout(): void
    {
        $this->assertSame(0, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->assertSame(30, (int) $this->f['model']->fresh()->stock);
        $this->assertSame(0, DB::table('checkout_receipts')->where('user_id', $this->f['buyer']->id)->count());
    }

    private function api(string $method, string $path, string $actor, array $data = [])
    {
        Auth::forgetGuards();
        return $this->json($method, '/api/v2' . $path, $data, ['Authorization' => 'Bearer ' . $this->f[$actor . 'Token']]);
    }
}
