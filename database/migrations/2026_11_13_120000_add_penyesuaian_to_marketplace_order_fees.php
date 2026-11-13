<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian potongan langsung dari Shopee (sumber `shopee`, get_escrow_detail): penyesuaian
 * SETELAH dana dilepas (retur/refund setelah cair, kompensasi) disimpan terpisah dari
 * `saldo_masuk`. saldo_masuk tetap = dana pesanan saat cair (dipakai koreksi jurnal pencairan);
 * saldo_akhir = setelah penyesuaian (= "Penghasilan Akhir" Seller Centre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_order_fees', function (Blueprint $table) {
            $table->json('penyesuaian')->nullable()->after('saldo_masuk');
            $table->decimal('saldo_akhir', 18, 2)->nullable()->after('penyesuaian');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_order_fees', function (Blueprint $table) {
            $table->dropColumn(['penyesuaian', 'saldo_akhir']);
        });
    }
};
