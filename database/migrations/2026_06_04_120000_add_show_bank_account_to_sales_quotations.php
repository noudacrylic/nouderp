<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opsi per-penawaran: tampilkan info rekening bank di cetak Surat Penawaran.
 * Default mati (sebagian customer butuh verifikasi rekening, jadi dibuat opsional).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->boolean('show_bank_account')->default(false)->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', fn (Blueprint $t) => $t->dropColumn('show_bank_account'));
    }
};
