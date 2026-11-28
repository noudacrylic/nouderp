<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kategori Harga Custom "Tempat Obat" — TO-8-M2-2 (19×8×6) ukuran custom.
 *
 * Setting Rp50.000, markup 60%, sisa 15% (sama dengan Frame Mahar; pemilik menyesuaikan lewat
 * "Simpan jadi bawaan" di layar Hitung). Grosir: normal pada 50 pcs, grosir 100 pcs ke atas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('custom_price_categories')->where('code', 'tempat_obat')->exists()) {
            return;
        }

        $cnc = DB::table('production_departments')->where('name', 'like', '%CNC%')->value('id');
        $asm = DB::table('production_departments')->where('name', 'like', '%ssembl%')->value('id');

        DB::table('custom_price_categories')->insert([
            'code'                   => 'tempat_obat',
            'name'                   => 'Tempat Obat',
            'setup_cost'             => 50000,
            'markup_percent'         => 60,
            'waste_percent'          => 15,
            'min_order_enabled'      => false,
            'cnc_department_id'      => $cnc,
            'assembly_department_id' => $asm,
            // Keputusan pemilik 28 Nov: harga normal dihitung pada 50 pcs dan pesanan di bawahnya
            // tetap harga normal; grosir mulai 100 pcs.
            'params'                 => json_encode([
                'grosir_minimal_order'    => 50,
                'grosir_mulai'            => 100,
                'grosir_bawah_min_normal' => 1,
            ]),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('custom_price_categories')->where('code', 'tempat_obat')->delete();
    }
};
