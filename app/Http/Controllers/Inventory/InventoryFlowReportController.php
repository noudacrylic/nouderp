<?php

namespace App\Http\Controllers\Inventory;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Arus Barang & HPP — kapan barang MASUK, kapan KELUAR, dan kapan harga pokoknya
 * benar-benar masuk buku besar.
 *
 * Dibuat karena dua hal itu TIDAK terjadi bersamaan di ERP ini: Surat Jalan mengurangi stok
 * (ledger + lapisan FIFO) tanpa menjurnal apa pun, sedangkan `Dr 5001 HPP / Cr 1130 Persediaan`
 * baru ditulis saat FAKTUR. Selama pesanan belum difakturkan, barangnya sudah keluar gudang
 * tapi buku besar masih menganggapnya ada.
 *
 * Kolom "Jurnal HPP" memperlihatkan persis itu per baris, dan ringkasan di atas menjumlahkan
 * selisihnya untuk periode terpilih.
 */
class InventoryFlowReportController extends Controller
{
    /** Label manusiawi untuk `inventory_ledgers.transaction_type`. */
    private const LABEL = [
        'sale'                              => 'Penjualan (Surat Jalan)',
        'sales_void'                        => 'Void Surat Jalan',
        'sales_return'                      => 'Retur Penjualan',
        'purchase'                          => 'Pembelian',
        'purchase_void'                     => 'Void Pembelian',
        'opening'                           => 'Saldo Awal',
        'adjustment_in'                     => 'Penyesuaian Masuk',
        'adjustment_out'                    => 'Penyesuaian Keluar',
        'production_order'                  => 'Hasil Produksi',
        'production_order_void'             => 'Void Hasil Produksi',
        'production_material'               => 'Pemakaian Bahan',
        'production_material_addition'      => 'Tambahan Bahan',
        'production_material_addition_void' => 'Void Tambahan Bahan',
        'production_cancel'                 => 'Batal Produksi',
        'transfer_in'                       => 'Transfer Masuk',
        'transfer_out'                      => 'Transfer Keluar',
        'reconciliation'                    => 'Rekonsiliasi Stok',
        'correction'                        => 'Koreksi',
    ];

    /** transaction_type → [tabel dokumen, kolom nomor]. Sisanya tampil tanpa nomor. */
    private const DOC = [
        'sale'                              => ['sales_deliveries', 'delivery_number'],
        'sales_void'                        => ['sales_deliveries', 'delivery_number'],
        'sales_return'                      => ['sales_returns', 'return_number'],
        'purchase'                          => ['purchase_invoices', 'invoice_number'],
        'purchase_void'                     => ['purchase_invoices', 'invoice_number'],
        'production_order'                  => ['production_orders', 'order_number'],
        'production_order_void'             => ['production_orders', 'order_number'],
        'production_material'               => ['production_orders', 'order_number'],
        'production_material_addition'      => ['production_orders', 'order_number'],
        'production_material_addition_void' => ['production_orders', 'order_number'],
        'production_cancel'                 => ['production_orders', 'order_number'],
        'adjustment_in'                     => ['inventory_adjustments', 'number'],
        'adjustment_out'                    => ['inventory_adjustments', 'number'],
        'transfer_in'                       => ['inventory_transfers', 'number'],
        'transfer_out'                      => ['inventory_transfers', 'number'],
    ];

    public function index(Request $request)
    {
        $productId   = $request->integer('product_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $dari        = $request->get('dari') ?: now()->subDays(30)->toDateString();
        $sampai      = $request->get('sampai') ?: now()->toDateString();

        $base = DB::table('inventory_ledgers as l')
            ->whereDate('l.created_at', '>=', $dari)
            ->whereDate('l.created_at', '<=', $sampai)
            ->when($productId, fn ($q) => $q->where('l.product_id', $productId))
            ->when($warehouseId, fn ($q) => $q->where('l.warehouse_id', $warehouseId));

        $rows = (clone $base)
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->orderBy('l.created_at')->orderBy('l.id')
            ->select([
                'l.id', 'l.created_at', 'l.transaction_type', 'l.transaction_id', 'l.product_id',
                'l.qty_in', 'l.qty_out', 'l.balance',
                'p.name as product_name', 'p.sku', 'w.name as warehouse_name',
            ])
            ->paginate(per_page_size())
            ->withQueryString();

        $this->lengkapiDokumen($rows->getCollection());

        return view('erp.inventory.reports.flow', [
            'rows'        => $rows,
            'ringkasan'   => $this->ringkasan($dari, $sampai, $productId, $warehouseId),
            'products'    => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'warehouses'  => Warehouse::orderBy('name')->get(['id', 'name']),
            'productId'   => $productId,
            'warehouseId' => $warehouseId,
            'dari'        => $dari,
            'sampai'      => $sampai,
        ]);
    }

    /**
     * Isi nomor dokumen + status HPP tiap baris, dikelompokkan per jenis supaya tidak N+1.
     *
     * Untuk baris Surat Jalan: nilai HPP diambil dari `sales_delivery_items.cogs_total` (nilai
     * FIFO saat barang keluar), lalu dicek apakah SO-nya sudah punya faktur non-void — sebab
     * faktur itulah yang menjurnal HPP-nya. Tanpa faktur, nilai itu belum masuk buku besar.
     */
    private function lengkapiDokumen($baris): void
    {
        foreach (self::DOC as $type => [$tabel, $kolom]) {
            $ids = $baris->where('transaction_type', $type)->pluck('transaction_id')
                ->filter()->unique()->all();
            if (empty($ids)) continue;

            $map = DB::table($tabel)->whereIn('id', $ids)->pluck($kolom, 'id');
            foreach ($baris->where('transaction_type', $type) as $b) {
                $b->doc_number = $map[$b->transaction_id] ?? null;
            }
        }

        $sjIds = $baris->whereIn('transaction_type', ['sale', 'sales_void'])
            ->pluck('transaction_id')->filter()->unique()->all();
        if (empty($sjIds)) return;

        $hpp = DB::table('sales_delivery_items')
            ->whereIn('sales_delivery_id', $sjIds)
            ->selectRaw('sales_delivery_id, product_id, SUM(cogs_total) total')
            ->groupBy('sales_delivery_id', 'product_id')
            ->get()->keyBy(fn ($r) => $r->sales_delivery_id . '-' . $r->product_id);

        // Apakah Surat Jalan ini sudah punya faktur non-void (= HPP sudah dijurnal)?
        // Dua jalur: lewat SO, atau melekat langsung ke SJ (kasir/POS tanpa SO).
        $sj = DB::table('sales_deliveries')->whereIn('id', $sjIds)
            ->get(['id', 'sales_order_id', 'invoice_id'])->keyBy('id');

        $viaSo = DB::table('sales_invoices')
            ->whereIn('sales_order_id', $sj->pluck('sales_order_id')->filter()->unique()->values())
            ->where('status', '!=', 'void')
            ->pluck('invoice_number', 'sales_order_id');

        $viaSj = DB::table('sales_invoices')
            ->whereIn('id', $sj->pluck('invoice_id')->filter()->unique()->values())
            ->where('status', '!=', 'void')
            ->pluck('invoice_number', 'id');

        foreach ($baris->whereIn('transaction_type', ['sale', 'sales_void']) as $b) {
            $k = $b->transaction_id . '-' . ($b->product_id ?? '');
            $b->nilai_fifo = (float) ($hpp[$k]->total ?? 0);

            $d = $sj[$b->transaction_id] ?? null;
            $b->faktur_number = $d
                ? (($d->sales_order_id ? ($viaSo[$d->sales_order_id] ?? null) : null)
                    ?? ($d->invoice_id ? ($viaSj[$d->invoice_id] ?? null) : null))
                : null;
        }
    }

    /**
     * Ringkasan periode, semuanya dari SATU sumber: baris Surat Jalan di rentang ini, dipecah
     * berdasarkan apakah SO-nya sudah punya faktur non-void.
     *
     * SENGAJA TIDAK membandingkan "keluar" dengan saldo akun 5001 pada periode yang sama.
     * Barang yang keluar akhir Juli baru difakturkan Agustus, jadi perbandingan antar-periode
     * itu menghasilkan selisih puluhan juta yang cuma beda waktu — bukan kesalahan, dan malah
     * menutupi masalah yang sebenarnya. Yang dicari di sini: barang yang sudah keluar gudang
     * dan fakturnya TIDAK PERNAH terbit, karena harga pokoknya tak akan pernah masuk buku besar.
     */
    private function ringkasan(string $dari, string $sampai, ?int $productId, ?int $warehouseId): array
    {
        $q = fn () => DB::table('inventory_ledgers as l')
            ->join('sales_deliveries as sd', 'sd.id', '=', 'l.transaction_id')
            ->join('sales_delivery_items as di', function ($j) {
                $j->on('di.sales_delivery_id', '=', 'l.transaction_id')
                  ->on('di.product_id', '=', 'l.product_id');
            })
            ->where('l.transaction_type', 'sale')
            // Surat Jalan yang di-void sudah dinetralkan baris `sales_void` (stoknya kembali),
            // jadi jangan dihitung sebagai barang keluar — kalau ikut, angkanya menggelembung
            // oleh pengiriman yang sebenarnya tak pernah jadi.
            ->where('sd.status', '!=', 'void')
            ->whereDate('l.created_at', '>=', $dari)->whereDate('l.created_at', '<=', $sampai)
            ->when($productId, fn ($w) => $w->where('l.product_id', $productId))
            ->when($warehouseId, fn ($w) => $w->where('l.warehouse_id', $warehouseId));

        // Faktur bisa melekat lewat DUA jalur: pesanan (marketplace/penjualan biasa) ATAU
        // langsung ke Surat Jalan (kasir/POS yang memang tanpa SO). Mengecek lewat SO saja
        // membuat 44 Surat Jalan kasir terbaca "belum difakturkan" padahal fakturnya ada.
        $adaFaktur = fn ($w) => $w->select(DB::raw(1))->from('sales_invoices as si')
            ->where('si.status', '!=', 'void')
            ->where(function ($o) {
                $o->whereColumn('si.sales_order_id', 'sd.sales_order_id')
                  ->orWhereColumn('si.id', 'sd.invoice_id');
            });

        $keluar        = (float) $q()->sum('di.cogs_total');
        $belumFaktur   = (float) $q()->whereNotExists($adaFaktur)->sum('di.cogs_total');
        $belumSemua    = (float) DB::table('inventory_ledgers as l')
            ->join('sales_deliveries as sd', 'sd.id', '=', 'l.transaction_id')
            ->join('sales_delivery_items as di', function ($j) {
                $j->on('di.sales_delivery_id', '=', 'l.transaction_id')
                  ->on('di.product_id', '=', 'l.product_id');
            })
            ->where('l.transaction_type', 'sale')
            ->where('sd.status', '!=', 'void')
            ->whereNotExists($adaFaktur)
            ->sum('di.cogs_total');

        return [
            'keluar'      => round($keluar, 2),
            'difakturkan' => round($keluar - $belumFaktur, 2),
            'belum'       => round($belumFaktur, 2),
            'belum_semua' => round($belumSemua, 2),
        ];
    }

    public static function labelJenis(?string $type): string
    {
        return self::LABEL[$type] ?? ($type ?: '-');
    }
}
