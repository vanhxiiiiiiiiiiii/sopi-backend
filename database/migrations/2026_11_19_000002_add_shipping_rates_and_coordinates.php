<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shipping_rate_settings')) {
            Schema::create('shipping_rate_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('hub_name', 120);
                $table->string('hub_address', 255);
                $table->decimal('hub_latitude', 10, 7);
                $table->decimal('hub_longitude', 10, 7);
                $table->decimal('base_fee', 14, 2);
                $table->unsignedInteger('included_weight_grams');
                $table->decimal('extra_kg_fee', 14, 2);
                $table->decimal('included_distance_km', 8, 2);
                $table->decimal('per_km_fee', 14, 2);
                $table->decimal('free_shipping_threshold', 14, 2)->nullable();
                $table->decimal('max_distance_km', 8, 2);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }
        // Demo rates use the same stored currency as the existing product catalogue (USD).
        DB::table('shipping_rate_settings')->insertOrIgnore([
            'id' => 1, 'hub_name' => 'Kho phân loại PTIT Hà Đông',
            'hub_address' => '96A Trần Phú, Hà Đông, Hà Nội',
            'hub_latitude' => 20.9809427, 'hub_longitude' => 105.7870861,
            'base_fee' => 2, 'included_weight_grams' => 1000, 'extra_kg_fee' => 1,
            'included_distance_km' => 3, 'per_km_fee' => 0.10,
            'free_shipping_threshold' => 100, 'max_distance_km' => 1500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['latitude', 'longitude'] as $column) {
            if (!Schema::hasColumn('addresses', $column)) {
                Schema::table('addresses', fn (Blueprint $table) => $table->decimal($column, 10, 7)->nullable());
            }
        }
        if (!Schema::hasColumn('orders', 'shipping_snapshot')) {
            Schema::table('orders', fn (Blueprint $table) => $table->json('shipping_snapshot')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('shipping_snapshot'));
        Schema::table('addresses', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::dropIfExists('shipping_rate_settings');
    }
};
