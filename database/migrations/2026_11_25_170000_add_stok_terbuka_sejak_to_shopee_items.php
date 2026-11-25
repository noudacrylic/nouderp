<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sejak kapan stok listing Shopee > 0 — untuk SKU custom (CS*) artinya "link sudah
 * diberikan ke pembeli, belum di-checkout". Panel Produk › Custom menampilkan umurnya
 * supaya link yang terlalu lama bisa dialihkan ke pembeli lain.
 *
 * Isi awal untuk yang sudah terbuka hanyalah PERKIRAAN: waktu harga terakhir diubah
 * (link custom lahir bersama harganya); tanpa itu, saat migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_items', function (Blueprint $table) {
            $table->dateTime('stok_terbuka_sejak')->nullable()->after('stok');
        });

        $terbuka = DB::table('shopee_items')->where('stok', '>', 0)->get(['id', 'product_id']);
        foreach ($terbuka as $r) {
            $kira = null;
            if ($r->product_id) {
                $kira = collect([
                    DB::table('product_channel_prices')->where('product_id', $r->product_id)
                        ->where('channel', 'shopee')->value('pushed_at'),
                    DB::table('products')->where('id', $r->product_id)->value('updated_at'),
                ])->filter()->max();
            }
            DB::table('shopee_items')->where('id', $r->id)->update(['stok_terbuka_sejak' => $kira ?? now()]);
        }
    }

    public function down(): void
    {
        Schema::table('shopee_items', function (Blueprint $table) {
            $table->dropColumn('stok_terbuka_sejak');
        });
    }
};
