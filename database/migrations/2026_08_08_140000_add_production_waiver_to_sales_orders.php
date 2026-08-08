<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengecualian gerbang produksi per-Sales Order.
 *
 * Bucket "Siap Proses" dihitung, bukan disimpan: SO yang punya item ber-sale_type 'preorder'
 * WAJIB punya minimal 1 OP non-cancelled yang semuanya finalized (FulfillmentReadinessService),
 * kalau tidak ia menetap di "Belum Siap" dengan alasan "Produksi belum selesai".
 *
 * Aturan itu benar untuk barang yang memang harus dibuat, tapi buntu untuk barang preorder yang
 * fisiknya SUDAH ADA tanpa pernah lewat produksi di ERP — mis. barang sisa dari order yang batal
 * (order aslinya di luar ERP, jadi tak ada OP-nya) yang dimasukkan lewat Stock Opname. OP-nya
 * dibatalkan karena tidak ada yang diproduksi, dan OP cancelled disaring keluar → pesanan tak
 * pernah bisa diproses padahal barangnya siap dipacking.
 *
 * Waiver ini HANYA melepas gerbang produksi. Gerbang stok ($shortages) tetap dihitung terpisah,
 * jadi kalau barangnya ternyata tidak ada / kurang, pesanannya balik sendiri ke "Belum Siap" —
 * waiver tidak bisa dipakai mengirim barang yang tidak ada.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('production_waived_at')->nullable()->after('process_failed_at');
            $table->string('production_waived_reason')->nullable()->after('production_waived_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['production_waived_at', 'production_waived_reason']);
        });
    }
};
