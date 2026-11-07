<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('customer_code', 30)->nullable()->unique()->after('id');
            $table->string('national_id', 20)->nullable()->unique()->after('customer_code');
            $table->string('account_status', 20)->default('active')->after('email_verified_at');
            $table->index('created_at');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->string('seller_code', 30)->nullable()->unique()->after('id');
            $table->string('tax_code', 30)->nullable()->unique()->after('seller_code');
            $table->string('phone', 20)->nullable()->after('tax_code');
            $table->string('email', 100)->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('banners');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('cover_image');
            $table->index('name');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('product_code', 40)->nullable()->unique()->after('id');
            $table->decimal('cost_price', 14, 2)->default(0)->after('avg_price');
            $table->string('unit', 30)->default('item')->after('cost_price');
            $table->index('name');
            $table->index('created_at');
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_code', 30)->unique();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('address');
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['shop_id', 'is_active']);
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_model_id')->constrained('product_models')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_model_id']);
            $table->index(['product_model_id', 'quantity']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_model_id')->constrained('product_models')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('movement_type', 30);
            $table->integer('quantity_change');
            $table->unsignedInteger('quantity_after');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_model_id', 'created_at']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 14, 2);
            $table->decimal('minimum_order_value', 14, 2)->default(0);
            $table->decimal('maximum_discount', 14, 2)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('max_uses');
            $table->unsignedInteger('max_uses_per_customer')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_code', 40)->nullable()->unique()->after('id');
            $table->foreignId('voucher_id')->nullable()->after('shop_id')->constrained('vouchers')->nullOnDelete();
            $table->decimal('subtotal', 14, 2)->default(0)->after('grand_total');
            $table->decimal('shipping_fee', 14, 2)->default(0)->after('subtotal');
            $table->decimal('discount_amount', 14, 2)->default(0)->after('shipping_fee');
            $table->decimal('payment_total', 14, 2)->default(0)->after('discount_amount');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('payment_total');
            $table->decimal('commission_amount', 14, 2)->default(0)->after('commission_rate');
            $table->index(['user_id', 'created_at']);
            $table->index(['shop_id', 'created_at']);
            $table->index(['status_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_model_id')->nullable()->constrained('product_models')->nullOnDelete();
            $table->string('product_code', 40)->nullable();
            $table->string('product_name', 255);
            $table->json('variations')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('voucher_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->decimal('discount_amount', 14, 2);
            $table->timestamp('used_at')->useCurrent();
            $table->index(['voucher_id', 'user_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code', 40)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('method', 30);
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_code', 60)->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('carrier', 100);
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->string('status', 30)->default('pending');
            $table->timestamps();
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'product_id']);
        });

        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_model_id')->constrained('product_models')->cascadeOnDelete();
            $table->decimal('old_price', 14, 2)->nullable();
            $table->decimal('new_price', 14, 2);
            $table->timestamp('effective_at')->useCurrent();
            $table->index(['product_model_id', 'effective_at']);
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id', 'order_id'], 'reviews_user_product_order_unique');
            $table->index(['product_id', 'rating']);
        });

        Schema::table('order_progresses', function (Blueprint $table) {
            $table->index(['order_id', 'created_at']);
        });

        // Legacy triggers deducted stock and counted sales as soon as an order was inserted.
        // The application service now performs these operations transactionally at confirmation/delivery time.
        DB::unprepared('DROP TRIGGER IF EXISTS new_order_is_created');
        DB::unprepared('DROP TRIGGER IF EXISTS trgieer_update_sold_product_when_order_is_created');

        DB::unprepared('CREATE TRIGGER record_product_model_price_change
            AFTER UPDATE ON product_models
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.price <=> NEW.price) THEN
                    INSERT INTO product_price_histories (product_model_id, old_price, new_price, effective_at)
                    VALUES (NEW.id, OLD.price, NEW.price, CURRENT_TIMESTAMP);
                END IF;
            END');
    }

    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS record_product_model_price_change');

        Schema::table('order_progresses', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'created_at']);
        });
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_user_product_order_unique');
            $table->dropIndex(['product_id', 'rating']);
        });

        Schema::dropIfExists('product_price_histories');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('voucher_usages');
        Schema::dropIfExists('order_items');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['shop_id', 'created_at']);
            $table->dropIndex(['status_id', 'created_at']);
            $table->dropColumn([
                'order_code', 'voucher_id', 'subtotal', 'shipping_fee', 'discount_amount',
                'payment_total', 'commission_rate', 'commission_amount',
            ]);
        });

        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('warehouses');

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['product_code']);
            $table->dropIndex(['name']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['product_code', 'cost_price', 'unit']);
        });
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropColumn('is_active');
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->dropUnique(['seller_code']);
            $table->dropUnique(['tax_code']);
            $table->dropColumn(['seller_code', 'tax_code', 'phone', 'email', 'is_active']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['customer_code']);
            $table->dropUnique(['national_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['customer_code', 'national_id', 'account_status']);
        });
    }
};
