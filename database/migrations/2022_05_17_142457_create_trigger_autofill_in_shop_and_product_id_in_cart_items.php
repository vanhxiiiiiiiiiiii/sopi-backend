<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::unprepared('CREATE TRIGGER autofill_in_shop_and_product_id_in_cart_items
            BEFORE INSERT ON cart_items
            FOR EACH ROW
            BEGIN
                SET NEW.product_id = (
                    SELECT product_id FROM product_models WHERE id = NEW.product_model_id
                );
                SET NEW.shop_id = (
                    SELECT shop_id FROM products WHERE id = NEW.product_id
                );
            END');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS autofill_in_shop_and_product_id_in_cart_items');
    }
};
