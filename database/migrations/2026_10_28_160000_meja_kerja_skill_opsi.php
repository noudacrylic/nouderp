<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan khusus per Skill Admin (mis. daftar SKU yang butuh desain untuk skill Desain
 * pesanan) — diubah admin dari halaman Pengaturan skill, bukan dari kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meja_kerja_skill', function (Blueprint $table) {
            $table->json('opsi')->nullable()->after('sla_menit');
        });
    }

    public function down(): void
    {
        Schema::table('meja_kerja_skill', function (Blueprint $table) {
            $table->dropColumn('opsi');
        });
    }
};
