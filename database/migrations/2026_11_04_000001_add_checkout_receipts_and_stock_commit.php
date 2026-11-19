<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_receipts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->json('order_ids');
            $table->primary(['user_id', 'request_key']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_committed_at')->nullable();
            $table->json('recipient_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['stock_committed_at', 'recipient_snapshot']));
        Schema::dropIfExists('checkout_receipts');
    }
};
