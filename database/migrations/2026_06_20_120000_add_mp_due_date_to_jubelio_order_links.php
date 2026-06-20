<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas kirim (ship-by) asli dari marketplace — diambil dari `due_date` detail order Jubelio
 * (UTC → dikonversi WIB). Dipakai sebagai "Batas kirim" di Pemrosesan Pesanan agar sesuai
 * dengan deadline marketplace, bukan estimasi lokal (order_date + lead time).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->timestamp('mp_due_date')->nullable()->after('snap_order_date');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn('mp_due_date');
        });
    }
};
