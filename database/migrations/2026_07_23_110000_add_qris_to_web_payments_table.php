<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metode pembayaran QRIS (QRISLY/Komerce) berdampingan dengan Transfer + Kode Unik.
 *
 * QRIS dinamis DIBAYAR PER GENERATE (Rp100), jadi string QR disimpan dan dipakai
 * ulang selama belum kedaluwarsa — `qris_generate_count` memantau pemborosan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_payments', function (Blueprint $table) {
            $table->string('method', 20)->default('transfer')->after('customer_id'); // transfer|qris
            $table->string('qris_history_id', 100)->nullable()->after('expected_amount');
            $table->text('qris_string')->nullable()->after('qris_history_id');
            $table->timestamp('qris_expires_at')->nullable()->after('qris_string');
            $table->unsignedSmallInteger('qris_generate_count')->default(0)->after('qris_expires_at');

            $table->index('qris_history_id');
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::table('web_payments', function (Blueprint $table) {
            $table->dropIndex(['qris_history_id']);
            $table->dropIndex(['method']);
            $table->dropColumn(['method', 'qris_history_id', 'qris_string', 'qris_expires_at', 'qris_generate_count']);
        });
    }
};
