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
        DB::unprepared('CREATE TRIGGER update_average_rating_after_insert
            AFTER INSERT ON product_reviews
            FOR EACH ROW
            BEGIN
                UPDATE shop_statistics
                SET avg_rating = COALESCE((
                    SELECT AVG(rating) FROM product_reviews WHERE shop_id = NEW.shop_id
                ), 0)
                WHERE shop_id = NEW.shop_id;

                UPDATE products
                SET avg_rating = COALESCE((
                    SELECT AVG(rating) FROM product_reviews WHERE product_id = NEW.product_id
                ), 0)
                WHERE id = NEW.product_id;
            END');

        DB::unprepared('CREATE TRIGGER update_average_rating_after_update
            AFTER UPDATE ON product_reviews
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.rating <=> NEW.rating)
                    OR OLD.shop_id <> NEW.shop_id
                    OR OLD.product_id <> NEW.product_id THEN
                    UPDATE shop_statistics
                    SET avg_rating = COALESCE((
                        SELECT AVG(rating) FROM product_reviews WHERE shop_id = OLD.shop_id
                    ), 0)
                    WHERE shop_id = OLD.shop_id;

                    UPDATE shop_statistics
                    SET avg_rating = COALESCE((
                        SELECT AVG(rating) FROM product_reviews WHERE shop_id = NEW.shop_id
                    ), 0)
                    WHERE shop_id = NEW.shop_id;

                    UPDATE products
                    SET avg_rating = COALESCE((
                        SELECT AVG(rating) FROM product_reviews WHERE product_id = OLD.product_id
                    ), 0)
                    WHERE id = OLD.product_id;

                    UPDATE products
                    SET avg_rating = COALESCE((
                        SELECT AVG(rating) FROM product_reviews WHERE product_id = NEW.product_id
                    ), 0)
                    WHERE id = NEW.product_id;
                END IF;
            END');

        DB::unprepared('CREATE TRIGGER update_average_rating_after_delete
            AFTER DELETE ON product_reviews
            FOR EACH ROW
            BEGIN
                UPDATE shop_statistics
                SET avg_rating = COALESCE((
                    SELECT AVG(rating) FROM product_reviews WHERE shop_id = OLD.shop_id
                ), 0)
                WHERE shop_id = OLD.shop_id;

                UPDATE products
                SET avg_rating = COALESCE((
                    SELECT AVG(rating) FROM product_reviews WHERE product_id = OLD.product_id
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
        DB::unprepared('DROP TRIGGER IF EXISTS update_average_rating_after_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS update_average_rating_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS update_average_rating_after_delete');
    }
};
