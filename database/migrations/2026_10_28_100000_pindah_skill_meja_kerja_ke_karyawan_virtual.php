<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skill Meja Kerja menjadi skill karyawan virtual Manajer Admin: nyala/mati kini mode
 * karyawan_virtual_skills ('admin.<skill>'), "sejak" = mode_diubah_at. Skill yang sudah
 * dinyalakan dipindah sebagai Otomatis dengan waktu nyalanya, lalu kolom lamanya dibuang.
 * meja_kerja_skill tinggal peran, bobot, dan tenggat.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('meja_kerja_skill')->where('aktif', true)->get() as $r) {
            DB::table('karyawan_virtual_skills')->updateOrInsert(['skill' => 'admin.' . $r->skill], [
                'mode' => 'otomatis', 'mode_diubah_at' => $r->aktif_sejak ?? now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('meja_kerja_skill', function (Blueprint $table) {
            $table->dropColumn(['aktif', 'aktif_sejak']);
        });
    }

    public function down(): void
    {
        Schema::table('meja_kerja_skill', function (Blueprint $table) {
            $table->boolean('aktif')->default(false)->after('skill');
            $table->dateTime('aktif_sejak')->nullable()->after('sla_menit');
        });

        foreach (DB::table('karyawan_virtual_skills')->where('skill', 'like', 'admin.%')->where('mode', 'otomatis')->get() as $r) {
            DB::table('meja_kerja_skill')->where('skill', substr($r->skill, 6))->update(['aktif' => true, 'aktif_sejak' => $r->mode_diubah_at]);
        }
    }
};
