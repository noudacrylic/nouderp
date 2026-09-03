<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan menerima notifikasi WhatsApp.
 *
 * Meta menuntut bukti opt-in, dan penerima yang tidak merasa mendaftar akan
 * memblokir — blokir menurunkan quality rating, yang menurunkan limit kirim.
 * Karena itu bawaannya FALSE: tanpa centang, tidak ada notifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('wa_opt_in')->default(false)->after('recipient_phone');
            $table->timestamp('wa_opt_in_at')->nullable()->after('wa_opt_in');
            // 'checkout_web' | 'input_manual' | 'chat' — dari mana persetujuan diambil.
            $table->string('wa_opt_in_source', 30)->nullable()->after('wa_opt_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['wa_opt_in', 'wa_opt_in_at', 'wa_opt_in_source']);
        });
    }
};
