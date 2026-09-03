<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda lampiran yang berkasnya sudah disapu masa-simpan.
 *
 * Barisnya sengaja TIDAK ikut dihapus: di thread nanti muncul "lampiran dihapus
 * otomatis" beserta tanggalnya, bukan gambar rusak tanpa keterangan — supaya
 * yang hilang terlihat sebagai keputusan, bukan sebagai kerusakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_attachments', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('download_error');
        });
    }

    public function down(): void
    {
        Schema::table('crm_attachments', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
