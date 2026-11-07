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

class CheckoutMysqlTest extends TestCase
{
    public function test_cod_checkout_commits_inventory_once_and_tracks_delivery(): void
    {
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Opt in with RUN_MYSQL_CHECKOUT_TESTS=1 on the migrated local MySQL database.');
        }

        DB::beginTransaction();
        try {
            $buyer = $this->account();
            $seller = $this->account();
            $other = $this->account();
            Shop::create(['id' => $seller->id, 'name' => 'Checkout fixture', 'slug' => Str::uuid()->toString()]);
            $address = Address::create([
                'full_name' => 'Test Recipient', 'phone' => '0901234567', 'address' => '1 Test Street',
                'town' => 'Test Ward', 'city' => 'Test District', 'state' => 'Test Province',
            ]);
            $buyer->addresses()->attach($address);
            $product = Product::create([
                'name' => 'Checkout test product', 'shop_id' => $seller->id, 'status_id' => 1, 'weight' => 100,
            ]);
            $model = $product->models()->create(['price' => 12.50, 'stock' => 3, 'variation_index' => []]);
            $buyerToken = $buyer->createToken('checkout-test')->plainTextToken;
            $sellerToken = $seller->createToken('checkout-test')->plainTextToken;
            $otherToken = $other->createToken('checkout-test')->plainTextToken;
            $item = ['product_model_id' => $model->id, 'quantity' => 2];
            $payload = [
                'request_key' => Str::uuid()->toString(), 'address_id' => $address->id,
                'orders' => [$item], 'payment_method' => 'cod', 'shipping_fee' => 999, 'payment_total' => 1,
            ];

            $this->api('POST', '/user/cart', $buyerToken, $item)->assertOk();
            $this->api('POST', '/user/cart', $buyerToken, [...$item, 'quantity' => 4])->assertUnprocessable();
            $this->api('GET', '/user/cart', $buyerToken)->assertOk()
                ->assertJsonPath('data.0.quantity', 2)->assertJsonPath('data.0.final_total_price', 25);
            $this->api('POST', '/user/orders/quote', $buyerToken)->assertOk()->assertJsonPath('data.payment_total', 25);
            $this->api('POST', '/user/orders', $buyerToken, [...$payload, 'voucher_code' => 'MISSING-VOUCHER'])->assertUnprocessable();
            $this->assertSame(3, (int) $model->fresh()->stock);
            $this->api('GET', '/user/cart', $buyerToken)->assertJsonCount(1, 'data');

            $unselected = $product->models()->create(['price' => 8.00, 'stock' => 4, 'variation_index' => []]);
            $this->api('POST', '/user/cart', $buyerToken, ['product_model_id' => $unselected->id, 'quantity' => 1])->assertOk();
            $this->api('POST', '/user/orders/quote', $buyerToken, ['model_ids' => [$model->id]])
                ->assertOk()->assertJsonPath('data.payment_total', 25);

            $created = $this->api('POST', '/user/orders', $buyerToken, $payload)->assertCreated()
                ->assertJsonPath('data.0.status_id', 1)->assertJsonPath('data.0.payments.0.status', 'pending')
                ->assertJsonPath('data.0.recipient_snapshot.full_name', 'Test Recipient');
            $id = $created->json('data.0.id');
            $paymentId = $created->json('data.0.payments.0.id');
            $this->assertSame(25.0, (float) $created->json('data.0.payment_total'));
            $this->assertSame(1, (int) $model->fresh()->stock);
            $this->api('GET', '/user/cart', $buyerToken)->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.product_model_id', $unselected->id);
            $this->assertSame(4, (int) $unselected->fresh()->stock);
            $this->api('POST', '/user/orders', $buyerToken, $payload)->assertCreated()->assertJsonPath('data.0.id', $id);
            $this->assertSame(1, Order::where('user_id', $buyer->id)->count());
            $this->assertSame(1, (int) $model->fresh()->stock);
            $this->api('GET', "/user/orders/{$id}", $otherToken)->assertNotFound();
            $this->api('POST', "/user/payments/{$paymentId}/pay", $buyerToken, ['transaction_reference' => 'invented'])
                ->assertConflict();
            $this->api('PUT', "/shop/orders/{$id}/status", $otherToken, ['status_id' => 2])->assertForbidden();
            $this->api('PUT', "/shop/orders/{$id}/status", $sellerToken, ['status_id' => 2])->assertOk();
            $this->assertSame(1, (int) $model->fresh()->stock);
            $this->api('PUT', "/shop/orders/{$id}/status", $sellerToken, ['status_id' => 3])->assertOk();
            $this->api('PUT', "/shop/orders/{$id}/status", $sellerToken, ['status_id' => 4])->assertOk();
            $this->api('GET', "/user/orders/{$id}", $buyerToken)->assertOk()
                ->assertJsonPath('data.status_id', 4)->assertJsonPath('data.payments.0.status', 'paid')
                ->assertJsonPath('data.progresses.3.status_id', 4);
            $this->api('PUT', "/shop/orders/{$id}/status", $sellerToken, ['status_id' => 6])->assertOk();
            $this->assertSame(3, (int) $model->fresh()->stock);

            $this->api('POST', '/user/cart', $buyerToken, $item)->assertOk();
            $second = $this->api('POST', '/user/orders', $buyerToken, [...$payload, 'request_key' => Str::uuid()->toString()])
                ->assertCreated()->json('data.0.id');
            $this->api('POST', "/user/orders/{$second}/cancel", $buyerToken)->assertOk()
                ->assertJsonPath('data.payments.0.status', 'cancelled');
            $this->assertSame(3, (int) $model->fresh()->stock);
        } finally {
            DB::rollBack();
        }
    }

    private function account(): User
    {
        $name = 'checkout_' . Str::random(12);

        return User::create(['username' => $name, 'email' => $name . '@example.test']);
    }

    private function api(string $method, string $path, string $token, array $data = [])
    {
        Auth::forgetGuards();

        return $this->json($method, '/api/v2' . $path, $data, ['Authorization' => 'Bearer ' . $token]);
    }
}
