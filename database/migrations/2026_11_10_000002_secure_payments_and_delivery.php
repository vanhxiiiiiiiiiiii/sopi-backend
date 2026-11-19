<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->change();
            $table->string('cvv', 3)->nullable()->change();
            $table->string('provider', 40)->nullable();
            $table->text('payment_token_reference')->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
        });
        // Keep display metadata; PAN/CVV are never a reusable gateway token.
        DB::table('credit_cards')->orderBy('id')->chunkById(100, function ($cards) {
            foreach ($cards as $card) {
                preg_match('/^(0[1-9]|1[0-2])\/?(\d{2}|\d{4})$/', (string) $card->expiry_date, $expiry);
                $year = isset($expiry[2]) ? (int) $expiry[2] : null;
                DB::table('credit_cards')->where('id', $card->id)->update([
                    'last_four' => $card->card_number ? substr($card->card_number, -4) : null,
                    'card_brand' => str_starts_with((string) $card->card_number, '4') ? 'Visa' : 'Thẻ ngân hàng',
                    'expiry_month' => isset($expiry[1]) ? (int) $expiry[1] : null,
                    'expiry_year' => $year !== null && $year < 100 ? 2000 + $year : $year,
                    'card_number' => null, 'cvv' => null,
                ]);
            }
        });
        Schema::create('shipping_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('delivery_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_provider_id')->constrained('shipping_providers')->restrictOnDelete();
            $table->string('username', 64)->unique();
            $table->string('email', 150)->unique();
            $table->string('name', 100);
            $table->string('phone', 20);
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('seller_status', 30)->default('pending');
        });
        DB::table('orders')->where('status_id', 2)->update(['seller_status' => 'ready_for_pickup']);
        DB::table('orders')->whereIn('status_id', [3, 4, 6])->update(['seller_status' => 'handed_over']);
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('shipping_provider_id')->nullable()->constrained('shipping_providers')->restrictOnDelete();
            $table->foreignId('shipper_id')->nullable()->constrained('delivery_staff')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->unsignedInteger('version')->default(0);
        });
        foreach (['pending' => 'PENDING', 'preparing' => 'READY_FOR_PICKUP', 'shipping' => 'IN_TRANSIT',
            'delivered' => 'DELIVERED', 'returned' => 'RETURNED', 'cancelled' => 'CANCELLED'] as $from => $to) {
            DB::table('shipments')->where('status', $from)->update(['status' => $to]);
        }
        Schema::create('shipment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 20);
            $table->string('event_type', 30)->default('status');
            $table->text('note')->nullable();
            $table->uuid('request_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['shipment_id', 'request_key']);
        });
        DB::table('shipments')->orderBy('id')->chunkById(100, function ($shipments) {
            foreach ($shipments as $shipment) {
                DB::table('shipment_histories')->insert([
                    'shipment_id' => $shipment->id, 'new_status' => $shipment->status,
                    'actor_role' => 'SYSTEM', 'event_type' => 'migration',
                    'note' => 'Tiếp nhận trạng thái hiện tại từ hệ thống cũ; không xác nhận lại việc giao hoặc thu tiền.',
                ]);
            }
        });
        Schema::create('cod_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('shipper_id')->constrained('delivery_staff')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('receipt_reference', 100);
            $table->timestamp('collected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_collections');
        Schema::dropIfExists('shipment_histories');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipper_id');
            $table->dropConstrainedForeignId('shipping_provider_id');
            $table->dropColumn(['assigned_at', 'failed_reason', 'version']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('seller_status'));
        Schema::dropIfExists('delivery_staff');
        Schema::dropIfExists('shipping_providers');
        // Deliberately never reconstruct sensitive card numbers or CVVs.
        Schema::table('credit_cards', fn (Blueprint $table) => $table->dropColumn([
            'provider', 'payment_token_reference', 'card_brand', 'last_four',
            'expiry_month', 'expiry_year', 'is_default', 'is_active',
        ]));
    }
};
