<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Chip label yang tampil di kolom kiri Inbox CRM, PER ADMIN.
 *
 * Tiap admin bekerja dengan label yang berbeda (CS desain tak butuh chip
 * "Menunggu Pelunasan"), dan menampilkan semuanya membuat kepala kolom
 * memakan lima-enam baris. Null = belum pernah diatur → bawaan dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('crm_label_tampil')->nullable()->after('pwa_crm');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('crm_label_tampil');
        });
    }
};
