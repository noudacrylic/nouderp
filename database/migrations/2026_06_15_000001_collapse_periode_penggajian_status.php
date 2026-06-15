<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gabungkan status periode penggajian: draft + imported → open.
 *
 * Pemisahan draft/imported dulu relevan saat data absensi bergantung impor Excel.
 * Sekarang data masuk langsung dari ADMS (fingerprint); Excel hanya cadangan dan
 * tidak lagi mengubah sifat periode. Status final: open | finalized | void.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Lebarkan enum agar 'open' valid (nilai lama masih dipertahankan sementara).
        DB::statement("ALTER TABLE sdm_periode_penggajian MODIFY COLUMN status ENUM('draft','imported','open','finalized','void') NOT NULL DEFAULT 'open'");

        // 2) Gabungkan draft + imported → open.
        DB::table('sdm_periode_penggajian')->whereIn('status', ['draft', 'imported'])->update(['status' => 'open']);

        // 3) Persempit enum ke set final.
        DB::statement("ALTER TABLE sdm_periode_penggajian MODIFY COLUMN status ENUM('open','finalized','void') NOT NULL DEFAULT 'open'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sdm_periode_penggajian MODIFY COLUMN status ENUM('draft','imported','open','finalized','void') NOT NULL DEFAULT 'draft'");
        DB::table('sdm_periode_penggajian')->where('status', 'open')->update(['status' => 'draft']);
        DB::statement("ALTER TABLE sdm_periode_penggajian MODIFY COLUMN status ENUM('draft','imported','finalized','void') NOT NULL DEFAULT 'draft'");
    }
};
