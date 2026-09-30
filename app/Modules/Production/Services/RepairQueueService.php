<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Collection;

/**
 * Antrean barang PERBAIKAN — daftar untuk menu Produksi › Barang Perbaikan.
 *
 * Barang retur berkondisi "Perbaikan" masuk Gudang Perbaikan (akun 1131, non-jual) dan
 * menunggu di sana sampai dibuatkan OP Perbaikan. Berbeda dengan Utuh (langsung stok jual)
 * atau Rusak/Tidak Kembali (langsung beban), perbaikan masih punya PROSES di ERP — dan tanpa
 * daftar ini tak ada yang tahu barang mana saja yang sedang menunggu.
 *
 *   menunggu : stok Gudang Perbaikan per SKU, DIKURANGI yang sudah dipesan OP aktif tapi
 *              belum diambil dari gudang — supaya satu unit tak terhitung dua kali.
 *   diproses : OP perbaikan yang masih berjalan (belum difinalisasi / dibatalkan).
 */
class RepairQueueService
{
    /** Status OP yang masih berjalan. */
    public const STATUS_AKTIF = ['draft', 'pending', 'confirmed', 'in_progress', 'partial', 'completed'];

    /** Tipe OP yang mengambil barang dari Gudang Perbaikan. */
    public const TIPE = ['perbaikan'];

    public function data(?string $search = null): array
    {
        $gudang = Warehouse::repairId();
        if (!$gudang) {
            return ['gudang' => null, 'menunggu' => collect(), 'diproses' => collect()];
        }

        $diproses = $this->opAktif();

        // Qty yang sudah dipesan OP aktif tapi belum dikonsumsi — masih fisik di gudang.
        $dipesan = $diproses->flatMap(fn ($op) => $op['barang'])
            ->groupBy('product_id')
            ->map(fn ($g) => $g->sum(fn ($b) => max(0, $b['qty'] - $b['terpakai'])));

        $search = trim((string) $search);
        $stok = ProductStock::with('product:id,sku,name')
            ->where('warehouse_id', $gudang)
            ->where('qty_on_hand', '>', 0)
            ->when($search !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p
                ->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->get()
            ->filter(fn ($s) => $s->product);

        $layers = StockLayer::where('warehouse_id', $gudang)
            ->where('qty_remaining', '>', 0)
            ->whereIn('product_id', $stok->pluck('product_id'))
            ->get()
            ->groupBy('product_id');

        $returIds = $layers->flatten()->where('source_type', 'sales_return')->pluck('source_id')->filter()->unique();
        $retur = SalesReturn::with('customer:id,name')->whereIn('id', $returIds)
            ->get(['id', 'return_number', 'return_date', 'customer_id', 'external_return_number'])->keyBy('id');

        $menunggu = $stok->map(function ($s) use ($layers, $retur, $dipesan) {
            $ly    = $layers->get($s->product_id, collect());
            $qty   = (float) $s->qty_on_hand;
            $nilai = (float) $ly->sum(fn ($l) => (float) $l->qty_remaining * (float) $l->unit_cost);
            $masuk = $ly->min('created_at');

            $asal = $ly->groupBy(fn ($l) => $l->source_type . ':' . $l->source_id)->map(function ($g) use ($retur) {
                $l = $g->first();
                $r = $l->source_type === 'sales_return' ? $retur->get($l->source_id) : null;

                return [
                    'jenis'   => $r ? 'retur' : $l->source_type,
                    'id'      => $r?->id,
                    'nomor'   => $r?->return_number ?? ($l->source_type . ($l->source_id ? ' #' . $l->source_id : '')),
                    'pembeli' => $r?->customer?->name,
                    'tanggal' => $r?->return_date ?? $l->created_at,
                    'qty'     => (float) $g->sum('qty_remaining'),
                ];
            })->sortBy('tanggal')->values();

            $dipesanQty = (float) ($dipesan[$s->product_id] ?? 0);

            return [
                'product_id' => (int) $s->product_id,
                'sku'        => $s->product->sku,
                'nama'       => $s->product->name,
                'qty'        => $qty,
                'dipesan'    => round(min($qty, $dipesanQty), 4),
                'tersedia'   => round(max(0, $qty - $dipesanQty), 4),
                'nilai'      => round($nilai, 2),
                'masuk'      => $masuk,
                'umur_hari'  => $masuk ? (int) \Carbon\Carbon::parse($masuk)->diffInDays(now()) : null,
                'asal'       => $asal,
            ];
        })->sortByDesc('umur_hari')->values();

        return ['gudang' => $gudang, 'menunggu' => $menunggu, 'diproses' => $diproses];
    }

    /** OP perbaikan yang masih berjalan, beserta barangnya. */
    private function opAktif(): Collection
    {
        return ProductionOrder::with('materials.product:id,sku,name')
            ->whereIn('type', self::TIPE)
            ->whereIn('status', self::STATUS_AKTIF)
            ->orderBy('id')
            ->get()
            ->map(fn (ProductionOrder $op) => [
                'id'      => $op->id,
                'nomor'   => $op->order_number ?? ('OP #' . $op->id),
                'status'  => $op->status,
                'tanggal' => $op->created_at,
                'barang'  => $op->materials->map(fn ($m) => [
                    'product_id' => (int) $m->product_id,
                    'sku'        => $m->product->sku ?? '',
                    'nama'       => $m->product->name ?? ('Produk #' . $m->product_id),
                    'qty'        => (float) $m->qty_required,
                    'terpakai'   => (float) $m->qty_consumed,
                ])->values(),
            ]);
    }
}
