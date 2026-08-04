<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas minimal DP jadi milik Sales Order, bukan lagi hanya diketik saat link dibuat.
 *
 * Kesepakatan "DP 30%" itu bagian dari kesepakatan penjualan: dicatat saat SO dibuat,
 * terbaca saat SO dilihat, dan otomatis dipakai tautan pembayaran. Sebelumnya batas ini
 * hanya hidup di baris transaksi Midtrans, jadi tidak terlihat di SO dan hilang jejaknya.
 *
 * Dua kolom, tapi hanya SATU yang terisi: persen bila kesepakatannya persentase (ikut
 * menyesuaikan kalau total SO berubah), nominal bila yang disepakati angka rupiah pasti.
 * Keduanya null → pakai bawaan 50%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('min_dp_percent', 5, 2)->nullable()->after('paid_amount');
            $table->decimal('min_dp_amount', 15, 2)->nullable()->after('min_dp_percent');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['min_dp_percent', 'min_dp_amount']);
        });
    }
};
