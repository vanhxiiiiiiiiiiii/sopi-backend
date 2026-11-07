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
        DB::unprepared('CREATE TRIGGER update_user_avatar_to_be_same_as_shop
            AFTER UPDATE ON shops
            FOR EACH ROW
            BEGIN
                IF COALESCE(@syncing_avatar, 0) = 0
                    AND NOT (OLD.avatar_image <=> NEW.avatar_image) THEN
                    SET @syncing_avatar = 1;
                    UPDATE user_profiles
                    SET avatar_image = NEW.avatar_image
                    WHERE user_profiles.user_id = OLD.id;
                    SET @syncing_avatar = 0;
                END IF;
            END');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS update_user_avatar_to_be_same_as_shop');
    }
};
