<?php

namespace App\Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stok bahan yang DIBELI (bukan diproduksi) — dasar skill Restok & Kedatangan di Meja Kerja
 * dan saran min_stock.
 *
 * Bahan beli = produk aktif berstok (bukan non_stock) yang pernah masuk PO (bukan aset) dan
 * bukan hasil utama BOM.
 * Tersedia = fisik di semua gudang aktif selain gudang Perbaikan − reservasi aktif; gudang
 * Cadangan ikut dihitung karena bahannya tetap milik sendiri, tak perlu dibeli lagi.
 */
class StokBahanService
{
    /** Pemakaian nyata: bahan terpakai produksi + terjual, dikurangi yang dibatalkan/kembali. */
    private const PAKAI_KELUAR = ['production_material', 'production_material_addition', 'sale'];
    private const PAKAI_BALIK  = ['production_cancel', 'sales_void', 'sales_return'];

    /** Tanpa riwayat datang & tanpa lead_time_days produk. */
    public const LEAD_TIME_BAWAAN = 3;

    /** @return Collection<int, int> */
    public function bahanBeliIds(): Collection
    {
        return DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.sale_type', '!=', 'non_stock') // lem, kertas foto, dsb. — stoknya tak dilacak
            ->whereExists(fn ($q) => $q->from('purchase_order_items as i')
                ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
                ->whereColumn('i.product_id', 'p.id')->where('i.is_asset', false)->where('o.status', '!=', 'cancelled'))
            ->whereNotExists(fn ($q) => $q->from('bom_outputs as b')->whereColumn('b.product_id', 'p.id')->where('b.output_type', 'main'))
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id);
    }

    /** @return array<int, float> */
    public function tersedia(array $ids): array
    {
        if (!$ids) return [];

        $fisik = DB::table('product_stocks as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->whereIn('s.product_id', $ids)->where('w.is_active', true)->where('w.is_repair', false)
            ->groupBy('s.product_id')->selectRaw('s.product_id, SUM(s.qty_on_hand) t')->pluck('t', 'product_id');
        $pesan = DB::table('stock_reservations')->whereIn('product_id', $ids)->where('status', 'active')
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) t')->pluck('t', 'product_id');

        $out = [];
        foreach ($ids as $id) $out[$id] = round((float) ($fisik[$id] ?? 0) - (float) ($pesan[$id] ?? 0), 4);
        return $out;
    }

    /**
     * Bahan yang masih ditunggu dari PO (posted, sisa qty belum difakturkan).
     *
     * @return array<int, true>
     */
    public function adaPoTerbuka(array $ids): array
    {
        if (!$ids) return [];

        return DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->whereIn('i.product_id', $ids)->where('o.status', 'posted')->whereColumn('i.qty_invoiced', '<', 'i.qty')
            ->distinct()->pluck('i.product_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** Pembelian terakhir: supplier, satuan, qty, harga — isian bawaan tombol "Buat PO". */
    public function pembelianTerakhir(int $productId): ?object
    {
        return DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->where('i.product_id', $productId)->where('i.is_asset', false)->where('o.status', '!=', 'cancelled')
            ->orderByDesc('o.po_date')->orderByDesc('o.id')
            ->first(['o.supplier_id', 's.name as supplier', 's.code as supplier_code', 'o.po_date', 'i.qty', 'i.unit', 'i.price']);
    }

    /** @return array<int, float> pemakaian bersih $hari terakhir */
    public function pemakaian(array $ids, int $hari): array
    {
        if (!$ids) return [];

        $rows = DB::table('inventory_ledgers')->whereIn('product_id', $ids)
            ->where('created_at', '>=', now()->subDays($hari))
            ->whereIn('transaction_type', [...self::PAKAI_KELUAR, ...self::PAKAI_BALIK])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(CASE WHEN transaction_type IN (?,?,?) THEN qty_out ELSE 0 END) - SUM(CASE WHEN transaction_type IN (?,?,?) THEN qty_in ELSE 0 END) t',
                [...self::PAKAI_KELUAR, ...self::PAKAI_BALIK])
            ->pluck('t', 'product_id');

        $out = [];
        foreach ($ids as $id) $out[$id] = max(0.0, (float) ($rows[$id] ?? 0));
        return $out;
    }

    /**
     * Lama datang nyata per bahan: rata-rata hari dari tanggal PO ke tanggal faktur (barang
     * diterima), 180 hari terakhir.
     *
     * @return array<int, float>
     */
    public function leadTimeNyata(array $ids): array
    {
        if (!$ids) return [];

        return DB::table('purchase_invoice_items as ii')
            ->join('purchase_invoices as f', 'f.id', '=', 'ii.purchase_invoice_id')
            ->join('purchase_orders as o', 'o.id', '=', 'f.purchase_order_id')
            ->whereIn('ii.product_id', $ids)->where('f.status', 'posted')
            ->where('f.invoice_date', '>=', now()->subDays(180)->toDateString())
            ->groupBy('ii.product_id')
            ->selectRaw('ii.product_id, AVG(GREATEST(DATEDIFF(f.invoice_date, o.po_date), 0)) t')
            ->pluck('t', 'product_id')
            ->map(fn ($t) => round((float) $t, 1))
            ->all();
    }

    /**
     * Pola beli barang non stok (lem, lakban, sekrup — stoknya tak dilacak, masuk overhead):
     * minimal 2 kali dibeli di tanggal berbeda → median jeda antar pembelian (hari) dan tanggal
     * beli terakhir. Median, bukan rata-rata, supaya satu kali beli borongan tak merusak jadwal.
     *
     * @return Collection<int, object{id: int, sku: string, name: string, kali: int, jeda: float, terakhir: Carbon}>
     */
    public function polaBeliNonStok(): Collection
    {
        $tanggal = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('p.sale_type', 'non_stock')->where('p.is_active', true)
            ->where('i.is_asset', false)->where('o.status', '!=', 'cancelled')
            ->distinct()->orderBy('o.po_date')
            ->get(['p.id', 'p.sku', 'p.name', 'o.po_date'])
            ->groupBy('id');

        return $tanggal->filter(fn ($rows) => $rows->count() >= 2)->map(function ($rows) {
            $hari = $rows->pluck('po_date')->map(fn ($d) => Carbon::parse($d));
            $jeda = collect(range(1, $hari->count() - 1))->map(fn ($i) => $hari[$i - 1]->diffInDays($hari[$i]))->sort()->values();
            $tengah = intdiv($jeda->count(), 2);
            $median = $jeda->count() % 2 ? $jeda[$tengah] : ($jeda[$tengah - 1] + $jeda[$tengah]) / 2;

            return (object) [
                'id' => (int) $rows->first()->id, 'sku' => $rows->first()->sku, 'name' => $rows->first()->name,
                'kali' => $hari->count(), 'jeda' => round((float) $median, 1), 'terakhir' => $hari->last(),
            ];
        })->values();
    }

    /** Tanggal PO dianggap mestinya sudah datang: tanggal perkiraan, atau tanggal PO + lead time. */
    public function jatuhTempoDatang(object $po, ?int $leadHari): Carbon
    {
        return $po->expected_date
            ? Carbon::parse($po->expected_date)->startOfDay()
            : Carbon::parse($po->po_date)->startOfDay()->addDays($leadHari ?: self::LEAD_TIME_BAWAAN);
    }

    /**
     * Saran min_stock = pemakaian per hari × (lama datang + hari cadangan), dibulatkan ke atas.
     *
     * @return Collection<int, object>
     */
    public function saranMinStok(int $hariPakai = 90, int $hariCadangan = 7): Collection
    {
        $ids = $this->bahanBeliIds()->all();
        $produk  = DB::table('products')->whereIn('id', $ids)->orderBy('sku')->get(['id', 'sku', 'name', 'base_unit', 'min_stock', 'lead_time_days']);
        $sisa    = $this->tersedia($ids);
        $pakai   = $this->pemakaian($ids, $hariPakai);
        $nyata   = $this->leadTimeNyata($ids);

        return $produk->map(function ($p) use ($sisa, $pakai, $nyata, $hariPakai, $hariCadangan) {
            [$lead, $sumber] = isset($nyata[$p->id]) ? [$nyata[$p->id], 'riwayat']
                : ($p->lead_time_days ? [(float) $p->lead_time_days, 'produk'] : [(float) self::LEAD_TIME_BAWAAN, 'bawaan']);
            $perHari = $pakai[$p->id] / $hariPakai;

            return (object) [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'unit' => $p->base_unit,
                'min_stock' => $p->min_stock !== null ? (float) $p->min_stock : null,
                'tersedia'  => $sisa[$p->id],
                'per_hari'  => round($perHari, 3),
                'lead'      => $lead,
                'lead_sumber' => $sumber,
                'saran'     => $perHari > 0 ? (float) ceil($perHari * ($lead + $hariCadangan)) : null,
            ];
        });
    }
}
