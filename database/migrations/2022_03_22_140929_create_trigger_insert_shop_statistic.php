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
        DB::unprepared('CREATE TRIGGER insert_shop_statistic
            AFTER INSERT ON shops
            FOR EACH ROW
            BEGIN
                INSERT INTO shop_statistics (shop_id) VALUES (NEW.id);
            END');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS insert_shop_statistic');
    }
};
