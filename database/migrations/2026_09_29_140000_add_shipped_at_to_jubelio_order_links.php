<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kapan paket pertama kali terlihat DISERAHKAN KE KURIR.
 *
 * Inilah garis antara BATAL dan RETUR (juga antara "Telah Diproses" dan "Dikirim"):
 * pembatalan sebelum titik ini = void, sesudahnya = retur. `last_status` tak bisa
 * dipakai karena tertimpa 'canceled' begitu pesanan batal, sehingga jejak "pernah
 * dikirim" hilang tepat saat dibutuhkan.
 *
 * Isi awal: pesanan yang statusnya kini shipped/completed/returned pasti sudah pernah
 * diserahkan. Yang sudah batal tidak bisa ditebak dari data ERP, jadi dibiarkan kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->timestamp('shipped_at')->nullable()->after('wms_completed_at');
        });

        DB::table('jubelio_order_links')
            ->whereIn('last_status', ['shipped', 'completed', 'returned'])
            ->update(['shipped_at' => DB::raw('COALESCE(mp_completed_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn('shipped_at');
        });
    }
};
