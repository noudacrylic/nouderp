<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal pengambilan (Ambil di Toko) yang di-set di SO. Dipakai menggantikan
 * "Batas kirim" pada kartu Pemrosesan Pesanan untuk pesanan ambil-toko.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('pickup_date')->nullable()->after('picked_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn('pickup_date'));
    }
};
