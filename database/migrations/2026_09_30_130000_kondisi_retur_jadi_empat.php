<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kondisi barang retur jadi EMPAT: Utuh · Perbaikan · Rusak · Tidak Kembali.
 *
 * Kondisi kini HANYA mengurus jurnal HPP; nasib uang dipindah ke Jenis Retur + Hasil Banding.
 * `hilang` (dana dikembalikan) dan `tetap` (tetap di pembeli) dilebur ke `tidak_kembali`.
 * Hanya DRAF yang disentuh — dokumen yang sudah diposting jurnalnya sudah terbentuk, dan
 * kondisi lamanya tetap terbaca (SalesReturn::CONDITIONS_LAMA).
 */
return new class extends Migration
{
    public function up(): void
    {
        $draf = DB::table('sales_returns')->where('status', 'draft')->pluck('id');
        if ($draf->isEmpty()) {
            return;
        }

        DB::table('sales_return_items')
            ->whereIn('sales_return_id', $draf)
            ->whereIn('condition', ['hilang', 'tetap'])
            ->update(['condition' => 'tidak_kembali']);

        DB::table('sales_return_items')
            ->whereIn('sales_return_id', $draf)
            ->whereNotNull('component_conditions')
            ->get(['id', 'component_conditions'])
            ->each(function ($r) {
                $cc = json_decode((string) $r->component_conditions, true);
                if (!is_array($cc)) {
                    return;
                }
                $baru = array_map(fn ($c) => in_array($c, ['hilang', 'tetap'], true) ? 'tidak_kembali' : $c, $cc);
                if ($baru !== $cc) {
                    DB::table('sales_return_items')->where('id', $r->id)->update(['component_conditions' => json_encode($baru)]);
                }
            });
    }

    public function down(): void
    {
        // Tidak bisa dibalik: asal `hilang` / `tetap` tidak tercatat.
    }
};
