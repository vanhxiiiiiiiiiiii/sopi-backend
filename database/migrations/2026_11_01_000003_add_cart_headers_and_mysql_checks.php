<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('cart_id')->nullable()->after('quantity')->constrained('carts')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_item_quantity CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_item_price CHECK (unit_price >= 0 AND line_total >= 0)');
        DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT chk_inventory_nonnegative CHECK (quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= quantity)');
        DB::statement('ALTER TABLE product_reviews ADD CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE vouchers ADD CONSTRAINT chk_voucher_values CHECK (discount_value > 0 AND minimum_order_value >= 0 AND max_uses > 0 AND max_uses_per_customer > 0)');
        DB::statement('ALTER TABLE vouchers ADD CONSTRAINT chk_voucher_dates CHECK (ends_at > starts_at)');
    }

    public function down()
    {
        DB::statement('ALTER TABLE vouchers DROP CHECK chk_voucher_dates');
        DB::statement('ALTER TABLE vouchers DROP CHECK chk_voucher_values');
        DB::statement('ALTER TABLE product_reviews DROP CHECK chk_review_rating');
        DB::statement('ALTER TABLE inventory_stocks DROP CHECK chk_inventory_nonnegative');
        DB::statement('ALTER TABLE order_items DROP CHECK chk_order_item_price');
        DB::statement('ALTER TABLE order_items DROP CHECK chk_order_item_quantity');

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_id');
        });
        Schema::dropIfExists('carts');
    }
};
