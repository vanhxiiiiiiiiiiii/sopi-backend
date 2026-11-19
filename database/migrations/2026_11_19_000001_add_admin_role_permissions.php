<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('administrator_roles', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique();
            $table->string('description', 255)->nullable();
        });
        Schema::table('administrators', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        foreach (config('admin_roles') as $code => $role) {
            if ($code === 'super_admin') {
                DB::table('administrator_roles')->updateOrInsert(['id' => 1], ['code' => $code, 'name' => $role['name'], 'description' => $role['description']]);
            } else {
                DB::table('administrator_roles')->insert(['code' => $code, 'name' => $role['name'], 'description' => $role['description']]);
            }
        }
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('administrators')->nullOnDelete();
            $table->string('action', 50);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->json('details');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
        // Restore legacy access before removing the new role definitions.
        DB::table('administrators')->update(['role_id' => 1]);
        DB::table('administrator_roles')->whereIn('code', ['logistics_admin', 'commerce_admin'])->delete();
        DB::table('administrator_roles')->where('id', 1)->update(['name' => 'Administrator']);
        Schema::table('administrators', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('administrator_roles', fn (Blueprint $table) => $table->dropColumn(['code', 'description']));
    }
};
