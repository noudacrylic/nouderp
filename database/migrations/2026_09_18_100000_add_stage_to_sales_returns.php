<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahap penanganan retur, terpisah dari `status` (yang mengurus akuntansi).
     *
     *   baru     → pembeli baru mengajukan; belum ada barang, belum ada angka.
     *   diproses → menunggu keputusan marketplace / barang sudah tiba & dicek.
     *   selesai  → sudah di-posting (stok + jurnal bergerak).
     *   batal    → kasus gugur / retur di-void.
     *
     * Retur dari Jubelio mendarat di `diproses` karena Jubelio baru tahu saat barang
     * sampai gudang — bukan saat pembeli mengajukan.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('stage', 20)->default('diproses')->after('status')->index();
        });

        DB::table('sales_returns')->where('status', 'posted')->update(['stage' => 'selesai']);
        DB::table('sales_returns')->where('status', 'draft')->update(['stage' => 'diproses']);
        DB::table('sales_returns')->whereIn('status', ['void', 'cancelled'])->update(['stage' => 'batal']);
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex(['stage']);
            $table->dropColumn('stage');
        });
    }
};
