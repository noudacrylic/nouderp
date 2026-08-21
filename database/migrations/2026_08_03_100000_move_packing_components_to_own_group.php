<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Packing dipindah dari Non Produksi ke kepala komponen sendiri.
 *
 * Biaya packing tidak mengikuti lamanya pabrik buka melainkan berapa paket yang
 * keluar, jadi pembaginya jumlah surat jalan — bukan jam operasional. Selama masih
 * menumpang di Non Produksi, tarifnya tampil per jam dan tidak bisa dibaca sebagai
 * biaya per pesanan.
 *
 * Yang dipindah hanya baris yang namanya menyebut "packing" — biaya lain di Non
 * Produksi tidak disentuh. Pembebanannya ke divisi produksi tidak berubah: Packing
 * tetap grup pool, hanya cara mengukur tarifnya yang berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('production_cost_components')
            ->where('group_key', 'non_produksi')
            ->where('name', 'like', '%packing%')
            ->update(['group_key' => 'packing', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('production_cost_components')
            ->where('group_key', 'packing')
            ->update(['group_key' => 'non_produksi', 'updated_at' => now()]);
    }
};
