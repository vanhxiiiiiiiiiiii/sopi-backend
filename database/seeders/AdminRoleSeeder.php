<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('administrator_roles')->updateOrInsert(['id' => AdminRole::Administrator->value], [
            'name' => 'Super Admin', 'code' => 'super_admin',
            'description' => config('admin_roles.super_admin.description'),
        ]);
    }
}
