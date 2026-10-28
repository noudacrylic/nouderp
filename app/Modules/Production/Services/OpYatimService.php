<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderStockCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OP pre-order UMUM (box charger, dsb — produk yang unitnya saling menggantikan) yang
 * pesanannya dibatalkan pembeli di tengah jalan.
 *
 *  a. OP masih antre, belum ada langkah yang dikerjakan → OP dibatalkan.
 *     (Void SO sudah mencobanya seketika; sapuan ini menangkap yang terlewat.)
 *  b. OP sudah mulai dikerjakan (dipotong, di Assembling, dst.) → OP DILEPAS dari SO:
 *     tanpa pemilik, skor Prioritas Rendah. Pesanan baru untuk produk yang sama yang
 *     KEKURANGAN barang (stok rak didahulukan) mengambil alih — pesanan ber-DP paling
 *     lama dulu — dan skornya kembali Otomatis. Bila tak ada yang pesan lalu OP tetap
 *     dikerjakan sampai selesai, hasilnya jadi stok biasa (finalisasi seperti biasa).
 *
 * Produk dibuat khusus (CS1–CS6) TIDAK disentuh: unitnya barang pesanan orang tertentu.
 *
 * Tidak bergantung urutan pemicu: dihitung ulang dari data tiap jalan. Netting pre-order
 * (PreorderAutoProductionService::uncoveredDemand) sudah menghitung OP yatim sebagai
 * produksi berjalan, jadi pesanan baru tidak memicu OP kedua selama OP yatim ada.
 */
class OpYatimService
{
    public const SKILL = ProductionOrderService::KV_OP_YATIM;

    public function __construct(
        private ProductionOrderService $orderService,
        private PreorderAutoProductionService $preorder,
        private SalesOrderStockCheck $stockCheck,
    ) {}

    /**
     * Satu putaran: lepas/batalkan OP milik pesanan batal, lalu carikan pemilik untuk OP yatim.
     *
     * @return array<int, array{jenis: string, production_order_id: int, order_number: string, sku: ?string,
     *   nama: ?string, so_lama: ?string, so_baru: ?string, dikerjakan: bool, simulasi: bool, reason: string}>
     */
    public function rapikan(bool $simulasi = false): array
    {
        return [...$this->lepasDariPesananBatal($simulasi), ...$this->carikanPemilik($simulasi)];
    }

    /** a + b: OP pre-order umum yang SO-nya void/batal. */
    public function lepasDariPesananBatal(bool $simulasi = false): array
    {
        $ops = ProductionOrder::with(['salesOrder', 'steps', 'outputs.product'])
            ->whereNotIn('status', ['cancelled', 'finalized', 'merged'])
            ->whereNull('merged_into_id')
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', ['void', 'cancelled']))
            ->orderBy('id')
            ->get();

        $hasil = [];
        foreach ($ops as $op) {
            $produk = $this->produkUtama($op);
            if (!$produk || !$produk->sharesStockAcrossOrders()) {
                continue; // dibuat khusus / bukan pre-order — bukan urusan skill ini
            }

            $base = $this->baris($op, $produk) + ['so_lama' => $op->salesOrder->order_number];
            $alasanSo = "Pesanan {$op->salesOrder->order_number} dibatalkan";

            if ($op->mergedChildren()->exists()) {
                $hasil[] = $base + ['jenis' => 'dilewati',
                    'reason' => "{$alasanSo}, tapi OP ini induk penggabungan task — diurus manual."];
                continue;
            }

            if (!$this->sudahDikerjakan($op)) {
                if ($simulasi) {
                    $hasil[] = $base + ['jenis' => 'batal', 'simulasi' => true,
                        'reason' => "{$alasanSo}; OP masih antre, belum dikerjakan → akan dibatalkan."];
                    continue;
                }
                try {
                    $this->orderService->cancel($op->id);
                    $op->refresh()->forceFill([
                        'notes' => trim(($op->notes ?? '') . "\n[Manager Produksi: dibatalkan — SO {$op->salesOrder->order_number} batal, OP belum dikerjakan]"),
                    ])->save();
                    $hasil[] = $base + ['jenis' => 'batal', 'dikerjakan' => true,
                        'reason' => "{$alasanSo}; OP masih antre, belum dikerjakan → dibatalkan."];
                } catch (\Throwable $e) {
                    $hasil[] = $base + ['jenis' => 'gagal', 'reason' => "Gagal membatalkan: {$e->getMessage()}"];
                }
                continue;
            }

            $tahap = $this->tahap($op);
            if ($simulasi) {
                $hasil[] = $base + ['jenis' => 'lepas', 'simulasi' => true,
                    'reason' => "{$alasanSo}; OP sudah dikerjakan ({$tahap}) → akan dilepas tanpa pemilik, Prioritas Rendah."];
                continue;
            }
            try {
                $this->orderService->lepasSalesOrder($op->id,
                    "Dilepas Manager Produksi: pesanan dibatalkan saat OP sudah dikerjakan ({$tahap})");
                $op->refresh()->forceFill([
                    'notes' => trim(($op->notes ?? '') . "\n[Manager Produksi: dilepas dari SO {$base['so_lama']} (batal) — Prioritas Rendah sampai ada pesanan baru]"),
                ])->save();
                $hasil[] = $base + ['jenis' => 'lepas', 'dikerjakan' => true,
                    'reason' => "{$alasanSo}; OP sudah dikerjakan ({$tahap}) → dilepas tanpa pemilik, skor Prioritas Rendah."];
            } catch (\Throwable $e) {
                Log::warning('OP yatim: gagal melepas OP dari SO batal', ['production_order_id' => $op->id, 'error' => $e->getMessage()]);
                $hasil[] = $base + ['jenis' => 'gagal', 'reason' => "Gagal melepas dari SO: {$e->getMessage()}"];
            }
        }

        return $this->lengkapi($hasil);
    }

    /** OP yatim → pesanan ber-DP paling lama yang kekurangan barang (stok rak didahulukan). */
    public function carikanPemilik(bool $simulasi = false): array
    {
        $ops = ProductionOrder::with(['outputs.product'])
            ->where('kv_skill', self::SKILL)
            ->whereNull('sales_order_id')
            ->whereNotIn('status', ['cancelled', 'finalized', 'merged'])
            ->whereNull('merged_into_id')
            ->orderBy('id')
            ->get();

        $hasil = [];
        $sudahDapat = []; // [so_id => [product_id => qty yang dijatahkan di putaran ini]] — simulasi tak menulis data
        foreach ($ops as $op) {
            $produk = $this->produkUtama($op);
            if (!$produk) {
                continue;
            }

            $so = $this->calonPemilik($produk, $sudahDapat);
            if (!$so) {
                continue; // tetap yatim, tak perlu dicatat tiap 30 menit
            }

            $base = $this->baris($op, $produk) + ['so_baru' => $so->order_number];
            $sudahDapat[$so->id][$produk->id] = ($sudahDapat[$so->id][$produk->id] ?? 0) + $this->qtyRencana($op, $produk->id);

            if ($simulasi) {
                $hasil[] = $base + ['jenis' => 'ambil', 'simulasi' => true,
                    'reason' => "Pesanan {$so->order_number} kekurangan {$produk->sku} → akan jadi pemilik OP, skor kembali Otomatis."];
                continue;
            }
            try {
                $this->orderService->gantiSalesOrder($op->id, $so->id,
                    "Diambil alih Manager Produksi: pesanan {$so->order_number} kekurangan {$produk->sku}");
                $op->refresh()->forceFill([
                    'notes' => trim(($op->notes ?? '') . "\n[Manager Produksi: diambil alih SO {$so->order_number} — skor kembali Otomatis]"),
                ])->save();
                $hasil[] = $base + ['jenis' => 'ambil', 'dikerjakan' => true,
                    'reason' => "Pesanan {$so->order_number} kekurangan {$produk->sku} → jadi pemilik OP, skor kembali Otomatis."];
            } catch (\Throwable $e) {
                Log::warning('OP yatim: gagal diambil alih', ['production_order_id' => $op->id, 'sales_order_id' => $so->id, 'error' => $e->getMessage()]);
                $hasil[] = $base + ['jenis' => 'gagal', 'reason' => "Gagal diambil alih {$so->order_number}: {$e->getMessage()}"];
            }
        }

        return $this->lengkapi($hasil);
    }

    /**
     * Pesanan paling lama (urut id) yang: confirmed, produksinya tidak di-waive, DP-nya
     * posted, masih menahan produk ini, produksinya belum direncanakan penuh, dan
     * KEKURANGAN barang menurut alokasi tertua-duluan (stok rak menutup lebih dulu).
     */
    private function calonPemilik(Product $produk, array $sudahDapat): ?SalesOrder
    {
        $soIds = DB::table('stock_reservations as r')
            ->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 'active')
            ->where('r.product_id', $produk->id)
            ->where('so.status', 'confirmed')
            ->whereNull('so.production_waived_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('sales_advances as a')
                ->whereColumn('a.sales_order_id', 'so.id')
                ->where('a.status', 'posted'))
            ->orderBy('so.id')
            ->distinct()
            ->pluck('so.id');

        foreach ($soIds as $soId) {
            $so = SalesOrder::with('items.product')->find($soId);
            if (!$so) continue;

            $dipesan = (float) $so->items->where('product_id', $produk->id)
                ->sum(fn ($i) => (float) $i->qty * (float) ($i->conversion_to_base ?: 1));
            $direncanakan = $this->preorder->plannedQtyForSalesOrder($so->id, $produk->id)
                + ($sudahDapat[$so->id][$produk->id] ?? 0);
            if ($direncanakan >= $dipesan - 0.0001) {
                continue; // produksinya sudah punya OP sendiri
            }

            $kurang = $this->stockCheck->shortagesForFulfillment([$so])[$so->id] ?? [];
            if (collect($kurang)->contains('product_id', $produk->id)) {
                return $so;
            }
        }

        return null;
    }

    /** Sama dengan guard ProductionOrderService::cancel: ada langkah yang tidak lagi antre. */
    private function sudahDikerjakan(ProductionOrder $op): bool
    {
        return $op->steps->contains(fn ($s) => $s->status !== 'pending' || $s->started_at !== null);
    }

    /** "langkah 2 Assembling sedang dikerjakan" — untuk alasan jurnal. */
    private function tahap(ProductionOrder $op): string
    {
        $langkah = $op->steps->sortBy('step_number');
        $aktif = $langkah->first(fn ($s) => !in_array($s->status, ['pending', 'completed', 'skipped'], true))
            ?? $langkah->last(fn ($s) => $s->status === 'completed');

        if (!$aktif) return 'sudah mulai';
        $selesai = $aktif->status === 'completed';

        return 'langkah ' . $aktif->step_number . ' ' . ($aktif->name ?: '') . ($selesai ? ' selesai' : ' berjalan');
    }

    private function produkUtama(ProductionOrder $op): ?Product
    {
        return $op->outputs->firstWhere('output_type', 'main')?->product;
    }

    private function qtyRencana(ProductionOrder $op, int $productId): float
    {
        return (float) $op->outputs->where('output_type', 'main')->where('product_id', $productId)->sum('qty_planned');
    }

    private function baris(ProductionOrder $op, Product $produk): array
    {
        return [
            'production_order_id' => $op->id,
            'order_number'        => $op->order_number,
            'product_id'          => $produk->id,
            'sku'                 => $produk->sku,
            'nama'                => $produk->name,
        ];
    }

    /** Kolom yang tak diisi sebuah baris diberi nilai bawaan (baris lebih dulu → nilainya menang). */
    private function lengkapi(array $hasil): array
    {
        return array_map(fn ($r) => $r + ['so_lama' => null, 'so_baru' => null, 'dikerjakan' => false, 'simulasi' => false], $hasil);
    }
}
