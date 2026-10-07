<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Models\InventoryAdjustment;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RepairGroup;
use App\Modules\Production\Models\RepairGroupItem;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Collection;

/**
 * Antrean barang PERBAIKAN — daftar untuk menu Produksi › Barang Perbaikan.
 *
 * Barang retur berkondisi "Perbaikan" dan barang rusak temuan opname (Penyesuaian tujuan
 * Perbaikan) masuk Gudang Perbaikan (akun 1131, non-jual) dan menunggu di sana sampai
 * dikelompokkan lalu dibuatkan OP Perbaikan.
 *
 *   menunggu : stok Gudang Perbaikan per SKU. "tersedia" = stok DIKURANGI yang sudah dipesan
 *              kelompok terbuka dan OP aktif yang belum mengambilnya dari gudang — supaya satu
 *              unit tak terhitung dua kali.
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

        $diproses   = $this->opAktif();
        $dipesanOp  = $this->dipesanOp($diproses);
        $dipesanKel = $this->dipesanKelompok();

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

        $flat = $layers->flatten();
        $retur = SalesReturn::with('customer:id,name')
            ->whereIn('id', $flat->where('source_type', 'sales_return')->pluck('source_id')->filter()->unique())
            ->get(['id', 'return_number', 'return_date', 'customer_id', 'external_return_number'])->keyBy('id');
        $opname = InventoryAdjustment::whereIn('id', $flat->where('source_type', 'adjustment_repair_in')->pluck('source_id')->filter()->unique())
            ->get(['id', 'number', 'date', 'title'])->keyBy('id');

        $menunggu = $stok->map(function ($s) use ($layers, $retur, $opname, $dipesanOp, $dipesanKel) {
            $ly    = $layers->get($s->product_id, collect());
            $qty   = (float) $s->qty_on_hand;
            $nilai = (float) $ly->sum(fn ($l) => (float) $l->qty_remaining * (float) $l->unit_cost);
            $masuk = $ly->min('created_at');

            $asal = $ly->groupBy(fn ($l) => $l->source_type . ':' . $l->source_id)
                ->map(fn ($g) => $this->asal($g, $retur, $opname))
                ->sortBy('tanggal')->values();

            $diOp  = (float) ($dipesanOp[$s->product_id] ?? 0);
            $diKel = (float) ($dipesanKel[$s->product_id] ?? 0);

            return [
                'product_id'  => (int) $s->product_id,
                'sku'         => $s->product->sku,
                'nama'        => $s->product->name,
                'qty'         => $qty,
                'di_op'       => round(min($qty, $diOp), 4),
                'di_kelompok' => round(min(max(0, $qty - $diOp), $diKel), 4),
                'tersedia'    => round(max(0, $qty - $diOp - $diKel), 4),
                'nilai'       => round($nilai, 2),
                'hpp_unit'    => $qty > 0 ? round($nilai / $qty, 2) : 0,
                'masuk'       => $masuk,
                'umur_hari'   => $masuk ? (int) \Carbon\Carbon::parse($masuk)->diffInDays(now()) : null,
                'asal'        => $asal,
            ];
        })->sortByDesc('umur_hari')->values();

        return ['gudang' => $gudang, 'menunggu' => $menunggu, 'diproses' => $diproses];
    }

    /**
     * Qty per SKU yang masih bebas dikelompokkan: stok Gudang Perbaikan − pesanan OP aktif −
     * pesanan kelompok terbuka. $kecualiKelompok = kelompok yang sedang diedit / dijadikan OP
     * (pesanannya sendiri tidak ikut mengurangi).
     *
     * @return array<int, float>
     */
    public function tersedia(?int $kecualiKelompok = null): array
    {
        $gudang = Warehouse::repairId();
        if (!$gudang) return [];

        $dipesanOp  = $this->dipesanOp($this->opAktif());
        $dipesanKel = $this->dipesanKelompok($kecualiKelompok);

        return ProductStock::where('warehouse_id', $gudang)
            ->where('qty_on_hand', '>', 0)
            ->get(['product_id', 'qty_on_hand'])
            ->mapWithKeys(fn ($s) => [(int) $s->product_id => max(0.0,
                (float) $s->qty_on_hand
                - (float) ($dipesanOp[$s->product_id] ?? 0)
                - (float) ($dipesanKel[$s->product_id] ?? 0))])
            ->all();
    }

    /** Kelompok perbaikan untuk tab Kelompok, terbaru dulu. */
    public function kelompok(?string $status = null)
    {
        return RepairGroup::with(['items.product:id,sku,name', 'productionOrder:id,order_number,status', 'creator:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("FIELD(status, 'terbuka', 'di_op', 'selesai', 'batal')")
            ->orderByDesc('id')
            ->paginate(per_page_size())
            ->withQueryString();
    }

    /** Qty pesanan OP aktif yang belum dikonsumsi — barangnya masih fisik di gudang. */
    private function dipesanOp(Collection $diproses): Collection
    {
        return $diproses->flatMap(fn ($op) => $op['barang'])
            ->groupBy('product_id')
            ->map(fn ($g) => $g->sum(fn ($b) => max(0, $b['qty'] - $b['terpakai'])));
    }

    /** Qty pesanan kelompok TERBUKA. Kelompok di_op sudah terwakili lewat OP-nya. */
    private function dipesanKelompok(?int $kecuali = null): Collection
    {
        return RepairGroupItem::query()
            ->whereHas('group', fn ($q) => $q->where('status', RepairGroup::TERBUKA)
                ->when($kecuali, fn ($q) => $q->where('id', '!=', $kecuali)))
            ->get(['product_id', 'qty'])
            ->groupBy('product_id')
            ->map(fn ($g) => (float) $g->sum('qty'));
    }

    /** Satu baris asal (dokumen yang memasukkan barang ke Gudang Perbaikan). */
    private function asal(Collection $g, Collection $retur, Collection $opname): array
    {
        $l   = $g->first();
        $qty = (float) $g->sum('qty_remaining');

        if ($l->source_type === 'sales_return' && ($r = $retur->get($l->source_id))) {
            return [
                'jenis' => 'retur', 'id' => $r->id, 'nomor' => $r->return_number,
                'pembeli' => $r->customer?->name, 'tanggal' => $r->return_date ?? $l->created_at, 'qty' => $qty,
            ];
        }

        if ($l->source_type === 'adjustment_repair_in' && ($a = $opname->get($l->source_id))) {
            return [
                'jenis' => 'opname', 'id' => $a->id, 'nomor' => $a->number,
                'pembeli' => null, 'tanggal' => $a->date ?? $l->created_at, 'qty' => $qty,
            ];
        }

        return [
            'jenis' => $l->source_type, 'id' => null,
            'nomor' => $l->source_type . ($l->source_id ? ' #' . $l->source_id : ''),
            'pembeli' => null, 'tanggal' => $l->created_at, 'qty' => $qty,
        ];
    }

    /** OP perbaikan yang masih berjalan, beserta barangnya. */
    private function opAktif(): Collection
    {
        return ProductionOrder::with(['materials.product:id,sku,name', 'repairGroup:id,production_order_id,number,name'])
            ->whereIn('type', self::TIPE)
            ->whereIn('status', self::STATUS_AKTIF)
            ->orderBy('id')
            ->get()
            ->map(fn (ProductionOrder $op) => [
                'id'       => $op->id,
                'nomor'    => $op->order_number ?? ('OP #' . $op->id),
                'status'   => $op->status,
                'tanggal'  => $op->created_at,
                'kelompok' => $op->repairGroup ? ['id' => $op->repairGroup->id, 'nomor' => $op->repairGroup->number, 'nama' => $op->repairGroup->name] : null,
                'barang'   => $op->materials->map(fn ($m) => [
                    'product_id' => (int) $m->product_id,
                    'sku'        => $m->product->sku ?? '',
                    'nama'       => $m->product->name ?? ('Produk #' . $m->product_id),
                    'qty'        => (float) $m->qty_required,
                    'terpakai'   => (float) $m->qty_consumed,
                ])->values(),
            ]);
    }
}
