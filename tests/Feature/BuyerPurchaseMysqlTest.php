<?php

namespace Tests\Feature;

use App\Models\Account\User\User;
use App\Models\Address;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Shop\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerPurchaseMysqlTest extends TestCase
{
    use \Tests\Concerns\UsesShippingRoutes;
    private bool $transactionStarted = false;
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the migrated local MySQL environment.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->fakeShippingRoutes();
        $buyer = $this->account();
        $seller = $this->account();
        $other = $this->account();
        Shop::create(['id' => $seller->id, 'name' => 'Purchase test shop', 'slug' => Str::uuid()->toString()]);
        $shop = Shop::findOrFail($seller->id);
        $address = Address::create(['latitude' => 20.99, 'longitude' => 105.8, 'full_name' => 'Purchase test recipient', 'phone' => '0901234567',
            'address' => 'Test street', 'town' => 'Test ward', 'city' => 'Test city', 'state' => 'Test province']);
        $buyer->addresses()->attach($address);
        $products = collect([1, 2])->map(fn ($i) => Product::create([
            'name' => 'Purchase test product ' . $i, 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 100,
        ]));
        $models = $products->map(fn ($product) => $product->models()->create(['price' => 12.5, 'stock' => 20, 'variation_index' => []]));
        $this->f = compact('buyer', 'seller', 'other', 'shop', 'address', 'products', 'models');
        foreach (['buyer', 'other'] as $actor) $this->f[$actor . 'Token'] = $this->f[$actor]->createToken('purchase-test')->plainTextToken;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_reviews_require_owned_completed_orders_and_support_every_product_once(): void
    {
        $order = $this->order();
        $payload = ['order_id' => $order->id, 'product_id' => $this->f['products'][0]->id, 'rating' => 4, 'comment' => 'Sản phẩm tốt.'];
        $this->api('POST', '/user/reviews', 'buyer', $payload)->assertUnprocessable();
        $order->update(['status_id' => 4]);
        $this->api('POST', '/user/reviews', 'other', $payload)->assertNotFound();
        $this->api('POST', '/user/reviews', 'buyer', array_diff_key($payload, ['product_id' => true]))->assertUnprocessable();
        $this->api('POST', '/user/reviews', 'buyer', [...$payload, 'product_id' => 2147483647])->assertUnprocessable();
        $this->api('POST', '/user/reviews', 'buyer', [...$payload, 'rating' => 0])->assertStatus(400);
        $this->api('POST', '/user/reviews', 'buyer', [...$payload, 'comment' => str_repeat('a', 2001)])->assertStatus(400);
        $review = $this->api('POST', '/user/reviews', 'buyer', $payload)->assertOk()->json('data');
        $this->api('POST', '/user/reviews', 'buyer', $payload)->assertOk()->assertJsonPath('data.id', $review['id']);
        $this->api('POST', '/user/reviews', 'buyer', [...$payload, 'rating' => 2])->assertConflict();
        $this->api('POST', '/user/reviews', 'buyer', [...$payload, 'product_id' => $this->f['products'][1]->id, 'rating' => 5, 'comment' => null])->assertOk();
        $this->assertSame(2, $order->reviews()->count());
        $this->assertEquals(4, $this->f['products'][0]->fresh()->avg_rating);
        $this->assertEquals(5, $this->f['products'][1]->fresh()->avg_rating);
        $this->api('GET', '/user/orders/' . $order->id, 'buyer')->assertOk()->assertJsonCount(2, 'data.reviews');
        $this->api('GET', '/user/orders?limit=10&purchase_status=completed', 'buyer')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonCount(2, 'data.data.0.reviews')
            ->assertJsonPath('data.data.0.shop.name', $this->f['shop']->name);
        $order->update(['status_id' => 6]);
        $this->api('POST', '/user/reviews', 'buyer', $payload)->assertUnprocessable();
    }

    public function test_repurchase_uses_current_prices_keeps_cart_quantities_and_does_not_change_orders_or_stock(): void
    {
        $order = $this->order();
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertUnprocessable();
        $order->update(['status_id' => 4]);
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'other')->assertNotFound();
        $model = $this->f['models'][0];
        $model->update(['price' => 19]);
        $stock = $model->fresh()->stock;
        $this->api('POST', '/user/cart', 'buyer', ['product_model_id' => $model->id, 'quantity' => 4])->assertOk();
        foreach ([1, 2] as $_) {
            $response = $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertOk()->assertJsonCount(2, 'data');
            $line = collect($response->json('data'))->firstWhere('product_model_id', $model->id);
            $this->assertSame(4, (int) $line['quantity']);
            $this->assertEquals(19, $line['unit_price']);
            $this->assertEquals(76, $line['final_total_price']);
        }
        $this->assertEquals($stock, $model->fresh()->stock);
        $this->assertSame(1, Order::where('user_id', $this->f['buyer']->id)->count());
        $this->assertEquals(52.7, $order->fresh()->payment_total);
        $this->assertSame(4, (int) $order->fresh()->status_id);
    }

    public function test_repurchase_rolls_back_all_cart_changes_when_one_product_is_unavailable(): void
    {
        $order = $this->order();
        $order->update(['status_id' => 4]);
        $this->f['models'][1]->update(['stock' => 0]);
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertUnprocessable();
        $this->api('GET', '/user/cart', 'buyer')->assertOk()->assertJsonCount(0, 'data');
        $this->f['models'][1]->update(['stock' => 10]);
        $this->f['shop']->update(['is_active' => false]);
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertUnprocessable();
        $this->f['shop']->update(['is_active' => true]);
        $this->f['products'][1]->update(['status_id' => 2]);
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertUnprocessable();
        $this->api('GET', '/user/cart', 'buyer')->assertJsonCount(0, 'data');
    }

    public function test_buyer_filters_do_not_treat_pending_cod_as_unpaid_and_legacy_orders_still_work(): void
    {
        $order = $this->order();
        $this->api('GET', '/user/orders?limit=10&purchase_status=unpaid', 'buyer')->assertOk()->assertJsonPath('data.total', 0);
        $this->api('GET', '/user/orders?limit=10&purchase_status=shipping', 'buyer')->assertOk()->assertJsonPath('data.total', 1);
        $order->payments()->update(['method' => 'card']);
        $this->api('GET', '/user/orders?limit=10&purchase_status=unpaid', 'buyer')->assertOk()->assertJsonPath('data.total', 1);
        $this->api('GET', '/user/orders?limit=10&purchase_status=shipping', 'buyer')->assertOk()->assertJsonPath('data.total', 0);
        $order->update(['status_id' => 3]);
        $this->api('GET', '/user/orders?limit=10&purchase_status=delivery', 'buyer')->assertJsonPath('data.total', 1);
        $order->update(['status_id' => 5]);
        $this->api('GET', '/user/orders?limit=10&purchase_status=cancelled', 'buyer')->assertJsonPath('data.total', 1);
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertUnprocessable();
        $order->update(['status_id' => 6]);
        $this->api('GET', '/user/orders?limit=10&purchase_status=returned', 'buyer')->assertJsonPath('data.total', 1);
        $order->update(['status_id' => 4]);
        $order->items()->delete(); // Legacy records only have the order-level product/model.
        $this->api('POST', '/user/reviews', 'buyer', ['order_id' => $order->id, 'rating' => 5])->assertOk()
            ->assertJsonPath('data.product_id', $order->product_id);
        $this->api('POST', '/user/orders/' . $order->id . '/buy-again', 'buyer')->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/user/orders?limit=10&purchase_status=completed', 'other')->assertJsonPath('data.total', 0);
    }

    private function order(): Order
    {
        $items = $this->f['models']->map(fn ($model) => ['product_model_id' => $model->id, 'quantity' => 2])->all();
        foreach ($items as $item) $this->api('POST', '/user/cart', 'buyer', $item)->assertOk();
        $id = $this->api('POST', '/user/orders', 'buyer', ['request_key' => Str::uuid()->toString(),
            'address_id' => $this->f['address']->id, 'payment_method' => 'cod', 'orders' => $items])->assertCreated()->json('data.0.id');
        return Order::findOrFail($id);
    }

    private function account(): User
    {
        $name = 'purchase_' . Str::random(12);
        return User::create(['username' => $name, 'email' => $name . '@example.test']);
    }

    private function api(string $method, string $path, string $actor, array $data = [])
    {
        Auth::forgetGuards();
        return $this->json($method, '/api/v2' . $path, $data, ['Authorization' => 'Bearer ' . $this->f[$actor . 'Token']]);
    }
}
