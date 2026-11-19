<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->string('expiry_date', 5)->nullable()->change();
            $table->text('registration_address')->nullable()->change();
            $table->string('postal_code', 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        // A rollback must not fabricate mandatory billing data for metadata-only cards.
    }
};
