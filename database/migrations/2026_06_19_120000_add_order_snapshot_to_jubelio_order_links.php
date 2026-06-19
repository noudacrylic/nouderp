<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan pesanan Jubelio (pelanggan, total, jumlah item, tanggal) untuk pesanan
 * yang BELUM jadi SO ERP (mis. belum dibayar). Dipakai menampilkan kartu info di tab
 * "Belum Siap" tanpa membuat dokumen ERP. Begitu dibayar → SO dibuat seperti biasa.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->string('snap_customer')->nullable()->after('cancel_requested_at');
            $table->decimal('snap_grand_total', 18, 2)->nullable()->after('snap_customer');
            $table->unsignedInteger('snap_item_count')->nullable()->after('snap_grand_total');
            $table->date('snap_order_date')->nullable()->after('snap_item_count');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn(['snap_customer', 'snap_grand_total', 'snap_item_count', 'snap_order_date']);
        });
    }
};
