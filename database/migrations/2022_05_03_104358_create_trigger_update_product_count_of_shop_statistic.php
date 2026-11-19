<?php

use App\Enums\ProductStatus;
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
        $published = ProductStatus::Published->value;
        $delisted = ProductStatus::Delisted->value;

        DB::unprepared("CREATE TRIGGER update_product_count_after_insert
            AFTER INSERT ON products
            FOR EACH ROW
            BEGIN
                IF NEW.status_id = $published THEN
                    UPDATE shop_statistics
                    SET
                        product_count = product_count + 1,
                        all_product_count = all_product_count + 1
                    WHERE shop_id = NEW.shop_id;
                ELSEIF NEW.status_id = $delisted THEN
                    UPDATE shop_statistics
                    SET all_product_count = all_product_count + 1
                    WHERE shop_id = NEW.shop_id;
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER update_product_count_after_update
            AFTER UPDATE ON products
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.status_id <=> NEW.status_id) OR NOT (OLD.shop_id <=> NEW.shop_id) THEN
                    IF OLD.status_id = $published THEN
                        UPDATE shop_statistics
                        SET
                            product_count = product_count - 1,
                            all_product_count = all_product_count - 1
                        WHERE shop_id = OLD.shop_id;
                    ELSEIF OLD.status_id = $delisted THEN
                        UPDATE shop_statistics
                        SET all_product_count = all_product_count - 1
                        WHERE shop_id = OLD.shop_id;
                    END IF;

                    IF NEW.status_id = $published THEN
                        UPDATE shop_statistics
                        SET
                            product_count = product_count + 1,
                            all_product_count = all_product_count + 1
                        WHERE shop_id = NEW.shop_id;
                    ELSEIF NEW.status_id = $delisted THEN
                        UPDATE shop_statistics
                        SET all_product_count = all_product_count + 1
                        WHERE shop_id = NEW.shop_id;
                    END IF;
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER update_product_count_after_delete
            AFTER DELETE ON products
            FOR EACH ROW
            BEGIN
                IF OLD.status_id = $published THEN
                    UPDATE shop_statistics
                    SET
                        product_count = product_count - 1,
                        all_product_count = all_product_count - 1
                    WHERE shop_id = OLD.shop_id;
                ELSEIF OLD.status_id = $delisted THEN
                    UPDATE shop_statistics
                    SET all_product_count = all_product_count - 1
                    WHERE shop_id = OLD.shop_id;
                END IF;
            END");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS update_product_count_after_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS update_product_count_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS update_product_count_after_delete');
    }
};
