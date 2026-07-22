<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intent pembayaran transfer bank per Sales Order (toko online).
 * Melacak kode unik, nominal yang diharapkan (grand_total unik), dan status
 * konfirmasi 3-lapis: auto (email/moota) → eskalasi Telegram → backstop 24 jam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->unsignedSmallInteger('unique_code');
            // Nominal transfer yang harus dicocokkan (grand_total setelah kode unik).
            $table->decimal('expected_amount', 15, 2);

            // awaiting → claimed → matched → confirmed | expired | cancelled
            $table->string('status')->default('awaiting');

            $table->timestamp('buyer_claimed_at')->nullable(); // pembeli tap "Saya sudah transfer" (mulai timer eskalasi)
            $table->timestamp('matched_at')->nullable();       // nominal cocok ditemukan (email/moota)
            $table->timestamp('confirmed_at')->nullable();     // pembayaran diposting ke ledger
            $table->string('confirmed_via')->nullable();       // email | moota | telegram | manual
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('matched_reference')->nullable();   // uid email / id mutasi

            $table->timestamp('escalated_at')->nullable();     // sudah dikirim ke Telegram
            $table->string('telegram_chat_id')->nullable();    // chat tujuan eskalasi (untuk edit pesan)
            $table->string('telegram_message_id')->nullable(); // message_id eskalasi (untuk edit anti-dobel)

            $table->foreignId('customer_payment_id')->nullable()->constrained('customer_payments')->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
            $table->index('expected_amount');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_payments');
    }
};
