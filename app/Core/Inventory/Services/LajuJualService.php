<?php

namespace App\Core\Inventory\Services;

use App\Models\InventoryLedger;

/**
 * Laju barang keluar karena dijual, dari kartu stok (inventory_ledgers type 'sale').
 *
 * Sengaja dari kartu stok, bukan baris SO: produk yang laku lewat bundel (mis.
 * AM-40x30x6 keluar 266/bulan lewat 14 SKU bundel, padahal baris SO-nya cuma 11)
 * hanya terlihat di sini. SJ yang di-void membalik barisnya (qty_in), jadi dihitung
 * bersih: Σ(qty_out − qty_in).
 *
 * Dipakai halaman Stok (kolom "Laku/hari" & "Cukup") dan skill isi antrean CNC,
 * supaya angka yang dilihat orang sama dengan yang dipakai sistem memutuskan.
 */
class LajuJualService
{
    public const HARI = 30;

    /**
     * @param  iterable<int>  $productIds
     * @return array<int,float> product_id => rata-rata keluar per hari (≥ 0)
     */
    public function perHari(iterable $productIds, int $hari = self::HARI): array
    {
        $ids = collect($productIds)->map(fn ($v) => (int) $v)->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        $total = InventoryLedger::whereIn('product_id', $ids)
            ->where('transaction_type', 'sale')
            ->where('created_at', '>=', now()->subDays($hari))
            ->selectRaw('product_id, SUM(qty_out) - SUM(qty_in) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return $ids->mapWithKeys(fn ($id) => [$id => max(0, (float) ($total[$id] ?? 0)) / $hari])->all();
    }

    /** Stok cukup untuk berapa hari; null = tidak laku dalam periode (tak terhingga). */
    public static function hariCukup(float $stok, float $perHari): ?float
    {
        if ($perHari <= 0) return null;
        return max(0, $stok) / $perHari;
    }
}
