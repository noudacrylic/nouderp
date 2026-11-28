<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kategori Harga Custom "Frame Mahar" — produk ready yang diminta beda ukuran/tebal/warna.
 *
 * Angka dari keputusan pemilik 25 Nov 2026: biaya setting Rp50.000 per pesanan (dibagi qty,
 * jadi pesanan banyak dapat harga lebih murah), markup 60%, sisa bahan 15%, tanpa minimal order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('custom_price_categories')->where('code', 'mahar')->exists()) {
            return;
        }

        $cnc = DB::table('production_departments')->where('name', 'like', '%CNC%')->value('id');
        $asm = DB::table('production_departments')->where('name', 'like', '%ssembl%')->value('id');

        DB::table('custom_price_categories')->insert([
            'code'                   => 'mahar',
            'name'                   => 'Frame Mahar',
            'setup_cost'             => 50000,
            'markup_percent'         => 60,
            'waste_percent'          => 15,
            'min_order_enabled'      => false,
            'cnc_department_id'      => $cnc,
            'assembly_department_id' => $asm,
            'params'                 => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('custom_price_categories')->where('code', 'mahar')->delete();
    }
};
