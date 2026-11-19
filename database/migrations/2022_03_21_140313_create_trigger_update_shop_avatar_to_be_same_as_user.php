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
        DB::unprepared("CREATE TRIGGER update_shop_avatar_to_be_same_as_user_when_inserting_shop
            BEFORE INSERT ON shops
            FOR EACH ROW
            BEGIN
                IF NEW.avatar_image = '' THEN
                    SET NEW.avatar_image = COALESCE(
                        (SELECT avatar_image FROM user_profiles WHERE user_id = NEW.id LIMIT 1),
                        NEW.avatar_image
                    );
                ELSEIF COALESCE(@syncing_avatar, 0) = 0 THEN
                    SET @syncing_avatar = 1;
                    UPDATE user_profiles
                    SET avatar_image = NEW.avatar_image
                    WHERE user_id = NEW.id;
                    SET @syncing_avatar = 0;
                END IF;
            END");

        DB::unprepared('CREATE TRIGGER update_shop_avatar_to_be_same_as_user_when_updating_user_profile
            AFTER UPDATE ON user_profiles
            FOR EACH ROW
            BEGIN
                IF COALESCE(@syncing_avatar, 0) = 0
                    AND NOT (OLD.avatar_image <=> NEW.avatar_image) THEN
                    SET @syncing_avatar = 1;
                    UPDATE shops
                    SET avatar_image = NEW.avatar_image
                    WHERE id = NEW.user_id;
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
        DB::unprepared('DROP TRIGGER IF EXISTS update_shop_avatar_to_be_same_as_user_when_inserting_shop');
        DB::unprepared('DROP TRIGGER IF EXISTS update_shop_avatar_to_be_same_as_user_when_updating_user_profile');
    }
};
