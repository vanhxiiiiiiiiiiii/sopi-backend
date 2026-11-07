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
        DB::unprepared('CREATE TRIGGER new_order_is_created
            AFTER INSERT ON orders
            FOR EACH ROW
            BEGIN
                INSERT INTO order_progresses (order_id) VALUES (NEW.id);

                IF NEW.product_model_id IS NOT NULL THEN
                    UPDATE product_models
                    SET stock = stock - NEW.quantity
                    WHERE id = NEW.product_model_id;
                END IF;

                DELETE FROM cart_items
                WHERE product_model_id = NEW.product_model_id AND user_id = NEW.user_id;
            END');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS new_order_is_created');
    }
};
