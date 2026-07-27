<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asal ongkir RajaOngkir dipindah dari shipping_settings(provider=rajaongkir).config
 * ke gudang, supaya alamat & titik asal terpusat di satu tempat (Gudang penjualan).
 * RajaOngkir memakai destination_id versi sendiri (bukan area_id Biteship), jadi tetap
 * butuh kolom tersendiri di warehouses.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('rajaongkir_origin_id', 50)->nullable()->after('kiriminaja_area_id');
            $table->string('rajaongkir_origin_label')->nullable()->after('rajaongkir_origin_id');
        });

        // Pindahkan origin lama (bila ada) ke gudang penjualan default (utamakan "Utama").
        $ro = DB::table('shipping_settings')->where('provider', 'rajaongkir')->first();
        $cfg = $ro && $ro->config ? (json_decode($ro->config, true) ?: []) : [];

        if (!empty($cfg['origin_id'])) {
            $whId = DB::table('warehouses')
                ->where('is_active', 1)->where('is_sellable', 1)
                ->orderByRaw("LOWER(name) = 'utama' DESC")
                ->orderBy('id')
                ->value('id');

            if ($whId) {
                DB::table('warehouses')->where('id', $whId)->update([
                    'rajaongkir_origin_id'    => (string) $cfg['origin_id'],
                    'rajaongkir_origin_label' => $cfg['origin_label'] ?? null,
                    'updated_at'              => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['rajaongkir_origin_id', 'rajaongkir_origin_label']);
        });
    }
};
