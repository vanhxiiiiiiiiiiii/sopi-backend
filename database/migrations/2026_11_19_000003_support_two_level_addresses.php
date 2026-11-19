<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Retain historical district data while allowing current province/commune addresses.
        Schema::table('addresses', fn (Blueprint $table) => $table->string('city', 50)->nullable()->change());
    }

    public function down(): void
    {
        DB::table('addresses')->whereNull('city')->update(['city' => '']);
        Schema::table('addresses', fn (Blueprint $table) => $table->string('city', 50)->nullable(false)->change());
    }
};
