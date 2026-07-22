<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan pembayaran "Transfer Bank + Kode Unik" (singleton id=1).
 * Rekening tujuan, akun kas ERP, rentang kode unik, menit eskalasi Telegram,
 * jam kedaluwarsa order, provider konfirmasi (email/moota/manual) + kredensial
 * adapter (config terenkripsi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);

            // Rekening tujuan yang ditampilkan ke pembeli.
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder')->nullable();

            // Akun kas/bank ERP yang di-debit saat pembayaran dikonfirmasi.
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            // Kode unik (rupiah) yang dikurangkan dari grand total.
            $table->unsignedSmallInteger('unique_code_min')->default(1);
            $table->unsignedSmallInteger('unique_code_max')->default(999);

            // Timer: eskalasi ke Telegram (menit) & auto-batal (jam).
            $table->unsignedSmallInteger('escalation_minutes')->default(10);
            $table->unsignedSmallInteger('expiry_hours')->default(24);

            // Sumber konfirmasi otomatis: email (IMAP) | moota | manual.
            $table->string('confirmation_provider')->default('email');

            // Kredensial adapter (IMAP host/user/pass, sender filter, token Moota, dll).
            // Terenkripsi at-rest.
            $table->text('config')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
