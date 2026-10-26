<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOM alternatif: beberapa BOM untuk produk utama yang sama (mis. box mahar 40x30 hitam —
 * BOM "pakai stok tutup" dan BOM "tutup sisa jadi sampingan"). Manager Produksi memakai
 * BOM dengan urutan terkecil yang bahannya cukup. Kosong = bukan alternatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->unsignedSmallInteger('urutan_pakai')->nullable()->after('auto_production');
        });
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropColumn('urutan_pakai');
        });
    }
};
