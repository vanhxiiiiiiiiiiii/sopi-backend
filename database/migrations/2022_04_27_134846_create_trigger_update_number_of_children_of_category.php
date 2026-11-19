<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // MySQL does not allow a trigger to update the table that fired it.
        // ProductCategoryService keeps this denormalized value in sync instead.
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // No database objects are created by this migration on MySQL.
    }
};
