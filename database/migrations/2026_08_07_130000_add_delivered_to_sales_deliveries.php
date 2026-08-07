<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "paket sudah SAMPAI di pembeli" pada Surat Jalan.
 *
 * Sebelumnya pesanan non-marketplace langsung dianggap Selesai begitu resinya dicetak —
 * padahal barangnya masih di jalan. Dengan kolom ini, pesanan berhenti di tab "Dikirim"
 * sampai ada yang menandai sampai (dari hasil Lacak paket), baru pindah ke "Selesai".
 *
 * Marketplace TIDAK memakai kolom ini: status sampainya datang dari Jubelio.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('resi_printed_at');
            $table->foreignId('delivered_by')->nullable()->after('delivered_at')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfillRiwayat();
    }

    /**
     * Riwayat: pesanan yang di ATURAN LAMA sudah dihitung "Selesai" tidak boleh mundur ke tab
     * "Dikirim" hanya karena kolom ini baru ada — kalau tidak, tab Dikirim langsung dibanjiri
     * pesanan lama yang barangnya sudah lama sampai.
     *
     * Yang ditandai sampai = yang dulu lolos gerbang lama: resi sudah dicetak sebelum hari ini,
     * atau kurir manual (memang tak pernah menerbitkan resi). Yang resinya belum dicetak dulu
     * memang masih di "Telah Diproses" → dibiarkan, dan sekarang akan lewat "Dikirim" seperti
     * pesanan baru.
     */
    private function backfillRiwayat(): void
    {
        $base = fn () => DB::table('sales_deliveries')
            ->where('status', 'posted')
            ->where('delivery_method', '!=', 'ambil_toko')
            ->whereNull('delivered_at');

        // Webhook Jubelio sudah merekam status kurir jauh sebelum kolom ini ada — yang sudah
        // DELIVERED jelas sampai, tak perlu menebak dari cetak resi.
        $base()
            ->where('shipping_status', 'delivered')
            ->update(['delivered_at' => DB::raw('COALESCE(delivery_date, updated_at)')]);

        $base()
            ->whereNotNull('resi_printed_at')
            ->whereDate('resi_printed_at', '<', now()->toDateString())
            ->update(['delivered_at' => DB::raw('COALESCE(delivery_date, resi_printed_at)')]);

        $manualCodes = DB::table('manual_couriers')->pluck('code')->all();
        if ($manualCodes) {
            $base()
                ->whereIn('shipping_courier_code', $manualCodes)
                ->update(['delivered_at' => DB::raw('COALESCE(delivery_date, created_at)')]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropColumn('delivered_at');
        });
    }
};
