<?php
namespace Tests\Feature;

use App\Models\Account\User\User;
use App\Models\Account\Admin\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerApprovalMysqlTest extends TestCase
{
    public function test_seller_access_requires_admin_approval(): void
    {
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1') {
            $this->markTestSkipped('Requires migrated MySQL.');
        }
        DB::beginTransaction();
        try {
            $name = 'seller_' . Str::random(12);
            $buyer = User::create(['username' => $name, 'email' => $name . '@example.test']);
            $token = $buyer->createToken('approval-test')->plainTextToken;
            $roleId = DB::table('administrator_roles')->insertGetId(['name' => $name]);
            $admin = Admin::forceCreate(['username' => 'admin_' . $name, 'email' => 'admin_' . $name . '@example.test',
                'password' => bcrypt('test-password'), 'role_id' => $roleId]);
            $adminToken = $admin->createToken('approval-test')->plainTextToken;
            $call = function ($method, $path, $key, $data = []) {
                Auth::forgetGuards();
                return $this->json($method, '/api/v2' . $path, $data, ['Authorization' => 'Bearer ' . $key]);
            };
            $call('GET', '/shop/products', $token)->assertForbidden();
            $call('GET', '/user/seller-application', $token)->assertOk()->assertJsonPath('data.shop', null);
            $call('POST', '/user/seller-application', $token, [
                'name' => 'Approval test shop', 'phone' => '0901234567',
                'email' => $name . '@example.test', 'address' => 'Test address',
            ])->assertOk()->assertJsonPath('data.application.status', 'pending');
            $id = DB::table('seller_applications')->where('user_id', $buyer->id)->value('id');
            $call('PUT', '/admin/seller-applications/' . $id, $token, ['status' => 'approved'])->assertUnauthorized();
            $call('GET', '/shop/dashboard', $token)->assertForbidden();
            $call('PUT', '/admin/seller-applications/' . $id, $adminToken, ['status' => 'approved'])->assertOk();
            $call('GET', '/shop/dashboard', $token)->assertOk();
            $call('GET', '/shop/products', $token)->assertOk();
            $call('GET', '/shop/dashboard', $adminToken)->assertUnauthorized();
            $call('PUT', '/admin/seller-applications/' . $id, $adminToken, ['status' => 'approved'])->assertConflict();
            $this->assertDatabaseHas('shops', ['id' => $buyer->id, 'name' => 'Approval test shop']);
        } finally {
            DB::rollBack();
        }
    }
}
