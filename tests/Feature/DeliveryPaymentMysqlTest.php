<?php

namespace Tests\Feature;

use App\Models\Account\Admin\Admin;
use App\Models\Account\User\User;
use App\Models\Address;
use App\Models\CreditCard;
use App\Models\Delivery\DeliveryStaff;
use App\Models\Delivery\ShippingProvider;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Shop\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryPaymentMysqlTest extends TestCase
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
        $buyer = $this->user();
        $seller = $this->user();
        Shop::create(['id' => $seller->id, 'name' => 'Delivery fixture', 'slug' => Str::uuid()->toString()]);
        $address = Address::create(['full_name' => 'Người nhận kiểm thử', 'phone' => '0901234567', 'address' => '1 Đường kiểm thử', 'town' => 'Phường', 'city' => 'Quận', 'state' => 'Tỉnh']);
        $buyer->addresses()->attach($address);
        $seller->addresses()->attach($address);
        $product = Product::create(['name' => 'Sản phẩm kiểm thử', 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 100]);
        $model = $product->models()->create(['price' => 12.5, 'stock' => 3, 'variation_index' => []]);
        $provider = ShippingProvider::create(['code' => 'TEST-' . Str::random(10), 'name' => 'Đơn vị kiểm thử', 'is_active' => true]);
        $password = Str::random(30);
        $staff = DeliveryStaff::create(['shipping_provider_id' => $provider->id, 'username' => 'shipper_' . Str::random(10),
            'email' => Str::random(12) . '@example.test', 'name' => 'Nhân viên kiểm thử', 'phone' => '0901234567', 'password' => $password, 'is_active' => true]);
        $otherStaff = DeliveryStaff::create(['shipping_provider_id' => $provider->id, 'username' => 'other_' . Str::random(10),
            'email' => Str::random(12) . '@example.test', 'name' => 'Nhân viên khác', 'phone' => '0901234568', 'password' => Str::random(30), 'is_active' => true]);
        $admin = Admin::forceCreate(['role_id' => DB::table('administrator_roles')->where('code', 'super_admin')->value('id'), 'username' => 'admin_' . Str::random(10), 'email' => Str::random(12) . '@example.test', 'password' => bcrypt(Str::random(30))]);
        $this->f = compact('buyer', 'seller', 'address', 'product', 'model', 'provider', 'staff', 'otherStaff', 'admin', 'password');
        foreach (['buyer', 'seller', 'admin'] as $key) $this->f[$key . 'Token'] = $this->f[$key]->createToken('test')->plainTextToken;
        foreach (['staff', 'otherStaff'] as $key) $this->f[$key . 'Token'] = $this->f[$key]->createToken('test', ['delivery'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_card_metadata_is_safe_and_unconfigured_gateway_never_creates_or_charges_an_order(): void
    {
        $card = $this->card($this->f['buyer']);
        $expired = $this->card($this->f['buyer'], ['expiry_year' => now()->year - 1]);
        $foreign = $this->card($this->f['seller']);
        $response = $this->api('GET', '/user/payment-methods', 'buyer')->assertOk()->assertJsonPath('data.card_available', false)->assertJsonCount(2, 'data.cards');
        foreach ($response->json('data.cards') as $record) {
            $this->assertArrayNotHasKey('card_number', $record);
            $this->assertArrayNotHasKey('cvv', $record);
            $this->assertArrayNotHasKey('payment_token_reference', $record);
            $this->assertFalse($record['usable']);
        }
        $this->api('POST', '/user/payment-methods/setup', 'buyer')->assertStatus(503);
        $this->api('POST', '/user/account/credit-cards', 'buyer', ['card_number' => '4111111111111111', 'cvv' => '123'])->assertStatus(400);
        $this->api('PUT', '/user/account/credit-cards/' . $foreign->id, 'buyer', ['cardholder_name' => 'Không được sửa'])->assertForbidden();
        $this->addCart();
        foreach ([[$expired->id, 422], [$foreign->id, 422], [$card->id, 503]] as [$id, $status]) {
            $this->api('POST', '/user/orders', 'buyer', [...$this->payload(), 'payment_method' => 'card', 'payment_method_id' => $id])->assertStatus($status);
        }
        $this->assertSame(0, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->assertSame(3, (int) $this->f['model']->fresh()->stock);
        $this->assertSame(0, DB::table('credit_cards')->whereNotNull('card_number')->orWhereNotNull('cvv')->count());
        $payload = $this->payload();
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'card_number' => '4111111111111111', 'cvv' => '123'])->assertStatus(400);
        $order = $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->json('data.0');
        $this->assertSame('cod', $order['payments'][0]['method']);
        $this->api('POST', '/user/orders', 'buyer', [...$payload, 'payment_method' => 'card', 'payment_method_id' => $card->id])->assertUnprocessable();
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->assertJsonPath('data.0.id', $order['id']);
        $this->assertSame(1, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->api('PUT', '/user/account/credit-cards/' . $card->id, 'buyer', ['cardholder_name' => 'TEN HIEN THI MOI'])->assertOk();
        $this->api('GET', '/user/account/credit-cards', 'buyer')->assertOk()->assertJsonMissingPath('data.0.card_number')->assertJsonMissingPath('data.0.cvv');
        $this->api('DELETE', '/user/account/credit-cards/' . $expired->id, 'buyer')->assertOk();
    }

    public function test_only_assigned_staff_can_deliver_and_cod_collection_is_a_separate_idempotent_event(): void
    {
        [$order, $shipment] = $this->readyShipment();
        foreach ([3, 4, 6] as $status) $this->api('PUT', '/shop/orders/' . $order['id'] . '/status', 'seller', ['status_id' => $status])->assertStatus(400);
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'buyer', $this->change('PICKED_UP', 0))->assertForbidden();
        $assign = $this->change(null, 0) + ['shipper_id' => $this->f['staff']->id];
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $assign)->assertOk()->assertJsonPath('data.status', 'ASSIGNED')->json('data');
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $assign)->assertOk()->assertJsonPath('data.version', 1);
        $this->api('GET', '/delivery/shipments/' . $shipment['id'], 'otherStaff')->assertNotFound();
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change('DELIVERED', 1))->assertUnprocessable();
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'staff', $this->change(null, 1) + ['amount' => 25, 'receipt_reference' => 'TEST-RECEIPT', 'cash_received' => true])->assertConflict();
        foreach (['PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'] as $status) $shipment = $this->advance($shipment, $status);
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change('DELIVERY_FAILED', $shipment['version']))->assertUnprocessable();
        $shipment = $this->advance($shipment, 'DELIVERY_FAILED', ['failed_reason' => 'Không liên lạc được người nhận']);
        $this->assertSame('Không liên lạc được người nhận', $shipment['failed_reason']);
        $shipment = $this->advance($shipment, 'OUT_FOR_DELIVERY');
        $delivered = $this->change('DELIVERED', $shipment['version']);
        $shipment = $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $delivered)->assertOk()->json('data');
        $this->assertSame('pending', $shipment['order']['payment_status']);
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $delivered)->assertOk();
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change('DELIVERED', $shipment['version']))->assertUnprocessable();
        $this->api('GET', '/user/orders/' . $order['id'], 'buyer')->assertOk()->assertJsonPath('data.shipment.status', 'DELIVERED')->assertJsonPath('data.payments.0.status', 'pending');
        $this->api('GET', '/shop/orders?limit=10', 'seller')->assertOk()->assertJsonPath('data.data.0.shipment.status', 'DELIVERED');
        $bad = $this->change(null, $shipment['version']) + ['amount' => 24, 'receipt_reference' => 'TEST-RECEIPT', 'cash_received' => true];
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'staff', $bad)->assertUnprocessable();
        $cod = $this->change(null, $shipment['version']) + ['amount' => 25, 'receipt_reference' => 'TEST-RECEIPT', 'cash_received' => true];
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'staff', $cod)->assertOk()->assertJsonPath('data.order.payment_status', 'paid');
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'staff', $cod)->assertOk();
        $this->assertSame(1, DB::table('cod_collections')->where('shipment_id', $shipment['id'])->count());
        $this->assertSame(1, Order::find($order['id'])->progresses()->where('status_id', 4)->count());
        $shipment = $this->api('GET', '/admin/shipments/' . $shipment['id'], 'admin')->assertOk()->json('data');
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/request-return', 'admin',
            $this->change(null, $shipment['version']) + ['note' => 'Hoàn hàng theo yêu cầu đã được xác minh.'])->assertOk()->assertJsonPath('data.status', 'RETURNING')->json('data');
        $shipment = $this->advance($shipment, 'RETURNED');
        $this->assertSame(3, (int) $this->f['model']->fresh()->stock);
        $this->assertSame('paid', $shipment['order']['payment_status']); // A refund cannot be invented.
    }

    public function test_cancellation_return_and_stale_versions_do_not_corrupt_inventory_or_history(): void
    {
        [$order, $shipment] = $this->readyShipment();
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $this->change(null, 0) + ['shipper_id' => $this->f['staff']->id])->assertOk()->json('data');
        $stale = $this->change('PICKED_UP', 1);
        $shipment = $this->advance($shipment, 'PICKED_UP');
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $stale)->assertConflict();
        $this->api('POST', '/shop/orders/' . $order['id'] . '/cancel', 'seller')->assertUnprocessable();
        $shipment = $this->advance($shipment, 'IN_TRANSIT');
        $shipment = $this->advance($shipment, 'OUT_FOR_DELIVERY');
        $shipment = $this->advance($shipment, 'DELIVERY_FAILED', ['failed_reason' => 'Người nhận từ chối nhận hàng']);
        $shipment = $this->advance($shipment, 'RETURNING');
        $shipment = $this->advance($shipment, 'RETURNED');
        $this->assertSame(6, (int) Order::find($order['id'])->status_id);
        $this->assertSame(3, (int) $this->f['model']->fresh()->stock);
        $this->assertSame('cancelled', $shipment['order']['payment_status']);
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change('RETURNED', $shipment['version']))->assertUnprocessable();

        [$order, $shipment] = $this->readyShipment();
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $this->change(null, 0) + ['shipper_id' => $this->f['staff']->id])->assertOk()->json('data');
        $this->api('POST', '/shop/orders/' . $order['id'] . '/cancel', 'seller')->assertOk();
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change('PICKED_UP', $shipment['version']))->assertConflict();
        $this->assertSame(3, (int) $this->f['model']->fresh()->stock);
    }

    public function test_delivery_login_and_administration_enforce_actual_roles_and_active_accounts(): void
    {
        $this->getJson('/api/v2/delivery/shipments')->assertUnauthorized();
        $this->api('GET', '/delivery/dashboard', 'seller')->assertForbidden();
        Auth::forgetGuards();
        $this->postJson('/api/v2/delivery/auth/sign-in', ['usernameOrEmail' => $this->f['staff']->username, 'password' => $this->f['password']])->assertOk()->assertJsonStructure(['token']);
        $this->f['staff']->update(['is_active' => false]);
        $this->api('GET', '/delivery/dashboard', 'staff')->assertForbidden();
        Auth::forgetGuards();
        $this->postJson('/api/v2/delivery/auth/sign-in', ['usernameOrEmail' => $this->f['staff']->username, 'password' => $this->f['password']])->assertUnprocessable();
        [$order, $shipment] = $this->readyShipment();
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $this->change(null, 0) + ['shipper_id' => $this->f['staff']->id])->assertUnprocessable();
    }

    public function test_admin_can_manage_real_accounts_and_reassign_incidents_without_leaking_card_data(): void
    {
        $provider = $this->api('POST', '/admin/shipping/providers', 'admin', ['code' => 'MANAGE-' . Str::random(8), 'name' => 'Đơn vị kiểm thử quản lý', 'phone' => '0901234567', 'is_active' => true])
            ->assertOk()->json('data');
        $password = Str::random(30);
        $account = ['shipping_provider_id' => $provider['id'], 'username' => 'manage_' . Str::random(9),
            'email' => Str::random(10) . '@example.test', 'name' => 'Nhân viên được cấp', 'phone' => '0901234567',
            'password' => $password, 'password_confirmation' => $password, 'is_active' => true];
        $response = $this->api('POST', '/admin/shipping/staff', 'admin', $account)->assertOk();
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->api('GET', '/admin/shipping/staff?q=' . $account['username'], 'admin')->assertOk()->assertJsonCount(1, 'data.data');
        [$order, $shipment] = $this->readyShipment();
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $this->change(null, 0) + ['shipper_id' => $this->f['staff']->id])->assertOk()->json('data');
        $shipment = $this->advance($shipment, 'PICKED_UP');
        $this->f['staff']->update(['is_active' => false]);
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $this->change(null, $shipment['version'])
            + ['shipper_id' => $this->f['otherStaff']->id, 'note' => 'Tiếp quản vì nhân viên cũ ngừng hoạt động.'])->assertOk()->assertJsonPath('data.status', 'PICKED_UP')->json('data');
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'otherStaff', $this->change('IN_TRANSIT', $shipment['version']))
            ->assertOk()->assertJsonMissingPath('data.order.payments')->assertJsonMissingPath('data.order.user_id');
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change('IN_TRANSIT', $shipment['version']))->assertForbidden();
        $updated = array_diff_key($account, array_flip(['password', 'password_confirmation']));
        $updated['is_active'] = false;
        $this->api('PUT', '/admin/shipping/staff/' . $response->json('data.id'), 'admin', $updated)->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_multi_seller_checkout_has_separate_orders_payments_and_shipments(): void
    {
        $this->addCart();
        $secondSeller = $this->user();
        Shop::create(['id' => $secondSeller->id, 'name' => 'Gian hàng thứ hai', 'slug' => Str::uuid()->toString()]);
        $secondSeller->addresses()->attach($this->f['address']);
        $this->f['secondSellerToken'] = $secondSeller->createToken('test')->plainTextToken;
        $product = Product::create(['name' => 'Sản phẩm gian hàng hai', 'shop_id' => $secondSeller->id, 'status_id' => 1, 'weight' => 100]);
        $model = $product->models()->create(['price' => 8, 'stock' => 2, 'variation_index' => []]);
        $this->api('POST', '/user/cart', 'buyer', ['product_model_id' => $model->id, 'quantity' => 1])->assertOk();
        $payload = $this->payload();
        $payload['orders'][] = ['product_model_id' => $model->id, 'quantity' => 1];
        $orders = $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->assertJsonCount(2, 'data')->json('data');
        $this->api('POST', '/user/orders', 'buyer', $payload)->assertCreated()->assertJsonCount(2, 'data');
        $this->assertEquals(33, collect($orders)->sum('payment_total'));
        $this->assertSame(1, (int) $model->fresh()->stock);
        foreach ($orders as $order) {
            $actor = (int) $order['shop_id'] === $this->f['seller']->id ? 'seller' : 'secondSeller';
            $this->api('POST', '/shop/orders/' . $order['id'] . '/confirm', $actor)->assertOk();
            $this->api('POST', '/shop/orders/' . $order['id'] . '/prepare', $actor)->assertOk();
            $this->api('POST', '/shop/orders/' . $order['id'] . '/ready', $actor, ['pickup_address_id' => $this->f['address']->id])->assertOk();
        }
        $this->assertSame(2, DB::table('shipments')->whereIn('order_id', array_column($orders, 'id'))->count());
        $this->api('GET', '/shop/orders?limit=10', 'seller')->assertOk()->assertJsonCount(1, 'data.data');
        $this->api('GET', '/shop/orders?limit=10', 'secondSeller')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_delivered_pending_cod_can_be_taken_over_after_staff_suspension_without_replaying_delivery(): void
    {
        [$order, $shipment] = $this->deliveredShipment();
        $deliveredAt = $shipment['delivered_at'];
        $this->assertFalse($shipment['can_assign']);
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin',
            $this->change(null, $shipment['version']) + ['shipper_id' => $this->f['otherStaff']->id, 'note' => 'Không được thay người đang hoạt động.'])->assertConflict();
        $this->f['staff']->update(['is_active' => false]);
        $this->api('GET', '/admin/shipments/' . $shipment['id'], 'admin')->assertOk()->assertJsonPath('data.can_assign', true);
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin',
            $this->change(null, $shipment['version']) + ['shipper_id' => $this->f['otherStaff']->id])->assertUnprocessable();
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin',
            $this->change(null, 0) + ['shipper_id' => $this->f['otherStaff']->id, 'note' => 'Tiếp quản COD tồn đọng.'])->assertConflict();
        $assignment = $this->change(null, $shipment['version']) + ['shipper_id' => $this->f['otherStaff']->id, 'note' => 'Nhân viên cũ đã nghỉ, chuyển đối soát COD có biên nhận.'];
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $assignment)->assertOk()
            ->assertJsonPath('data.status', 'DELIVERED')->assertJsonPath('data.order.payment_status', 'pending')
            ->assertJsonPath('data.can_assign', false)->assertJsonPath('data.delivered_at', $deliveredAt)->json('data');
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin', $assignment)->assertOk()->assertJsonPath('data.version', $shipment['version']);
        $history = DB::table('shipment_histories')->where('shipment_id', $shipment['id'])->where('request_key', $assignment['request_key'])->first();
        $this->assertNotNull($history);
        $this->assertSame('ADMIN', $history->actor_role);
        $this->assertStringContainsString('Nhân viên trước: ' . $this->f['staff']->id, $history->note);
        $this->assertSame(1, Order::findOrFail($order['id'])->progresses()->where('status_id', 4)->count());
        $this->assertSame(1, (int) $this->f['model']->fresh()->stock);
        $cod = $this->change(null, $shipment['version']) + ['amount' => 25, 'receipt_reference' => 'RECOVERY-RECEIPT', 'cash_received' => true];
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'staff', $cod)->assertForbidden();
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'otherStaff', [...$cod, 'amount' => 24])->assertUnprocessable();
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'otherStaff', $cod)->assertOk()->assertJsonPath('data.order.payment_status', 'paid');
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'otherStaff', $cod)->assertOk();
        $this->assertSame(1, DB::table('cod_collections')->where('shipment_id', $shipment['id'])->count());
        $this->assertEquals($this->f['otherStaff']->id, DB::table('cod_collections')->where('shipment_id', $shipment['id'])->value('shipper_id'));
        $this->f['otherStaff']->update(['is_active' => false]);
        $paid = $this->api('GET', '/admin/shipments/' . $shipment['id'], 'admin')->assertOk()->assertJsonPath('data.can_assign', false)->json('data');
        $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin',
            $this->change(null, $paid['version']) + ['shipper_id' => $this->f['staff']->id, 'note' => 'Không được tiếp quản COD đã thu.'])->assertConflict();
    }

    public function test_pending_cod_can_be_recovered_when_the_original_carrier_is_disabled(): void
    {
        [$order, $shipment] = $this->deliveredShipment();
        $provider = ShippingProvider::create(['code' => 'RECOVER-' . Str::random(10), 'name' => 'Đơn vị tiếp quản', 'is_active' => true]);
        $this->f['otherStaff']->update(['shipping_provider_id' => $provider->id]);
        $this->f['provider']->update(['is_active' => false]);
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin',
            $this->change(null, $shipment['version']) + ['shipper_id' => $this->f['otherStaff']->id, 'note' => 'Đơn vị cũ ngừng hoạt động, bàn giao đối soát COD.'])
            ->assertOk()->assertJsonPath('data.status', 'DELIVERED')->assertJsonPath('data.shipping_provider_id', $provider->id)->json('data');
        $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/collect-cod', 'otherStaff',
            $this->change(null, $shipment['version']) + ['amount' => 25, 'receipt_reference' => 'CARRIER-RECOVERY', 'cash_received' => true])
            ->assertOk()->assertJsonPath('data.order.payment_status', 'paid');
        $this->assertSame(4, (int) Order::findOrFail($order['id'])->status_id);
        $this->assertSame(1, (int) $this->f['model']->fresh()->stock);
    }

    private function deliveredShipment(): array
    {
        [$order, $shipment] = $this->readyShipment();
        $shipment = $this->api('POST', '/admin/shipments/' . $shipment['id'] . '/assign', 'admin',
            $this->change(null, $shipment['version']) + ['shipper_id' => $this->f['staff']->id])->assertOk()->json('data');
        foreach (['PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $status) {
            $shipment = $this->advance($shipment, $status);
        }
        return [$order, $shipment];
    }

    private function readyShipment(): array
    {
        $this->addCart();
        $order = $this->api('POST', '/user/orders', 'buyer', $this->payload())->assertCreated()->json('data.0');
        $this->api('POST', '/shop/orders/' . $order['id'] . '/ready', 'seller', ['pickup_address_id' => $this->f['address']->id])->assertUnprocessable();
        $this->api('POST', '/shop/orders/' . $order['id'] . '/confirm', 'seller')->assertOk();
        $this->api('POST', '/shop/orders/' . $order['id'] . '/prepare', 'seller')->assertOk();
        $ready = $this->api('POST', '/shop/orders/' . $order['id'] . '/ready', 'seller', ['pickup_address_id' => $this->f['address']->id])->assertOk()->json('data.shipment');
        $this->api('POST', '/shop/orders/' . $order['id'] . '/ready', 'seller', ['pickup_address_id' => $this->f['address']->id])->assertOk()->assertJsonPath('data.shipment.id', $ready['id']);
        $this->assertSame(1, DB::table('shipments')->where('order_id', $order['id'])->count());
        return [$order, $ready];
    }

    private function advance(array $shipment, string $status, array $extra = []): array
    {
        return $this->api('POST', '/delivery/shipments/' . $shipment['id'] . '/status', 'staff', $this->change($status, $shipment['version']) + $extra)->assertOk()->json('data');
    }
    private function change(?string $status, int $version): array
    {
        return array_filter(['request_key' => Str::uuid()->toString(), 'expected_version' => $version, 'status' => $status], fn ($v) => $v !== null);
    }
    private function addCart(): void { $this->api('POST', '/user/cart', 'buyer', ['product_model_id' => $this->f['model']->id, 'quantity' => 2])->assertOk(); }
    private function payload(): array { return ['request_key' => Str::uuid()->toString(), 'address_id' => $this->f['address']->id, 'payment_method' => 'cod', 'orders' => [['product_model_id' => $this->f['model']->id, 'quantity' => 2]]]; }
    private function user(): User { $name = 'delivery_' . Str::random(12); return User::create(['username' => $name, 'email' => $name . '@example.test']); }
    private function card(User $user, array $extra = []): CreditCard
    {
        return CreditCard::forceCreate(array_merge(['credit_cardable_type' => User::class, 'credit_cardable_id' => $user->id,
            'cardholder_name' => 'CHU THE', 'last_four' => '1234', 'card_brand' => 'Visa', 'expiry_month' => 12,
            'expiry_year' => now()->year + 2], $extra));
    }
    private function api(string $method, string $path, string $actor, array $data = [])
    {
        Auth::forgetGuards();
        return $this->json($method, '/api/v2' . $path, $data, ['Authorization' => 'Bearer ' . $this->f[$actor . 'Token']]);
    }
}
