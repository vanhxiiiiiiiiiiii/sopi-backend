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
        DB::unprepared('CREATE TRIGGER calculate_avg_price_after_insert
            AFTER INSERT ON product_models
            FOR EACH ROW
            BEGIN
                UPDATE products
                SET avg_price = COALESCE((
                    SELECT AVG(price) FROM product_models WHERE product_id = NEW.product_id
                ), 0)
                WHERE id = NEW.product_id;
            END');

        DB::unprepared('CREATE TRIGGER calculate_avg_price_after_update
            AFTER UPDATE ON product_models
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.price <=> NEW.price) OR OLD.product_id <> NEW.product_id THEN
                    UPDATE products
                    SET avg_price = COALESCE((
                        SELECT AVG(price) FROM product_models WHERE product_id = OLD.product_id
                    ), 0)
                    WHERE id = OLD.product_id;

                    UPDATE products
                    SET avg_price = COALESCE((
                        SELECT AVG(price) FROM product_models WHERE product_id = NEW.product_id
                    ), 0)
                    WHERE id = NEW.product_id;
                END IF;
            END');

        DB::unprepared('CREATE TRIGGER calculate_avg_price_after_delete
            AFTER DELETE ON product_models
            FOR EACH ROW
            BEGIN
                UPDATE products
                SET avg_price = COALESCE((
                    SELECT AVG(price) FROM product_models WHERE product_id = OLD.product_id
                ), 0)
                WHERE id = OLD.product_id;
            END');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS calculate_avg_price_after_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS calculate_avg_price_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS calculate_avg_price_after_delete');
    }
};
