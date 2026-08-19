<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PreorderAutoProductionService
{
    public function __construct(
        private BomService $bomService,
        private ProductionOrderService $orderService,
    ) {}

    /**
     * Untuk tiap item SO yang produk-nya pre-order: cari BOM auto, buat order produksi
     * dengan planned_cycles = qty item, lalu auto-confirm.
     *
     * Berbeda dengan AutoProductionService (ready stock):
     * - Trigger reaktif (saat SO confirmed), bukan scan periodik
     * - Tidak ada cek skip-if-running — tiap pesanan customer berdiri sendiri
     * - planned_cycles = SISA qty produk yang belum direncanakan di SO ini, 1 siklus = 1 unit
     *   (BOM wajib qty_per_cycle=1). Dipakai sisa, bukan qty baris, karena 1 produk bisa
     *   tersebar di beberapa baris SO — lihat runForItem().
     *
     * Return: list result per item untuk reporting/logging.
     */
    public function runForSalesOrder(SalesOrder $so): array
    {
        $so->loadMissing(['items.product', 'customer']);

        $results = [];
        foreach ($so->items as $idx => $item) {
            $results[] = $this->runForItem($so, $item, $idx + 1);
        }

        return $results;
    }

    /**
     * Sapuan menyeluruh: jalankan ulang evaluasi auto-produksi pre-order untuk SEMUA pesanan
     * yang kebutuhannya masih menggantung. Dipakai tombol "Jalankan Auto Produksi".
     *
     * Kenapa perlu, padahal sudah ada pemicu otomatis saat DP di-post?
     * Karena pemicu itu sekali jalan dan menilai keadaan DETIK ITU. Kalau saat DP masuk
     * stoknya kebetulan cukup, OP sengaja tidak dibuat (lihat runForItem: netting terhadap
     * barang yang sudah ada). Bila barang itu kemudian LENYAP — stok opname minus, rusak,
     * dipakai pesanan lain, retur ditolak — tidak ada apa pun yang mengevaluasi ulang, dan
     * pesanan tinggal menggantung tanpa OP sampai ketahuan manual.
     * Kasus nyata SP-260818MV4A09ED + SP-260818N01HBVTC (BC-2x2): saat DP masuk 18/8 stok
     * BC-2x2 ada 2 pcs sehingga dua-duanya di-skip; 19/8 opname ADJ-2026-00016 menetapkan
     * aktual 0 → 2 pesanan tanpa barang dan tanpa OP.
     *
     * Kandidat dipersempit ke pesanan yang memang berhak diproduksi, memakai gerbang yang
     * SAMA dengan pemicu otomatis (SalesAdvanceObserver) supaya tombol ini tidak pernah
     * membuat OP yang tidak akan dibuat oleh alur normal:
     * - punya reservasi stok AKTIF untuk produk pre-order (reservasi lepas begitu SJ dibuat
     *   atau pesanan batal, jadi ini persis daftar "masih berhutang barang")
     * - SO confirmed, produksinya tidak di-waive
     * - DP-nya sudah posted
     *
     * Keputusan buat/skip per item tetap sepenuhnya di runForItem — sapuan ini hanya
     * menentukan SIAPA yang dievaluasi, bukan melonggarkan syaratnya. Pesanan lama
     * didahulukan (urut id) supaya stok/produksi yang ada dialokasikan ke antrean terdepan.
     */
    public function sweepPendingSalesOrders(): array
    {
        $salesOrderIds = DB::table('stock_reservations as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('sales_orders as so', 'so.id', '=', 'r.sales_order_id')
            ->where('r.status', 'active')
            ->where('p.sale_type', 'preorder')
            ->where('so.status', 'confirmed')
            ->whereNull('so.production_waived_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('sales_advances as a')
                ->whereColumn('a.sales_order_id', 'so.id')
                ->where('a.status', 'posted'))
            ->orderBy('so.id')
            ->distinct()
            ->pluck('so.id')
            ->all();

        $results = [];

        foreach ($salesOrderIds as $salesOrderId) {
            $so = SalesOrder::with(['items.product', 'customer'])->find($salesOrderId);
            if (!$so) {
                continue;
            }

            // Dievaluasi satu per satu dan BERURUTAN — bukan dikumpulkan dulu. OP yang lahir
            // dari pesanan sebelumnya langsung terbaca sebagai "produksi berjalan" oleh
            // pesanan berikutnya (uncoveredDemand dihitung global), sehingga dua pesanan atas
            // produk yang sama tidak memicu dua OP untuk kebutuhan yang sama.
            foreach ($this->runForSalesOrder($so) as $result) {
                $results[] = $result + ['sales_order_number' => $so->order_number];
            }
        }

        return $results;
    }

    private function runForItem(SalesOrder $so, SalesOrderItem $item, int $lineNo): array
    {
        $base = [
            'sales_order_id'    => $so->id,
            'sales_order_item_id' => $item->id,
            'line_no'           => $lineNo,
            'product_id'        => $item->product_id,
            'created'           => false,
            'order_number'      => null,
            'reason'             => null,
        ];

        $product = $item->product;
        if (!$product) {
            return array_merge($base, ['reason' => 'Produk tidak ditemukan.']);
        }

        if ($product->sale_type !== 'preorder') {
            return array_merge($base, ['reason' => 'Bukan produk preorder, dilewati.']);
        }

        $bom = Bom::with('outputs')
            ->where('auto_production', true)
            ->whereHas('outputs', fn($q) => $q->where('output_type', 'main')
                                              ->where('product_id', $product->id))
            ->first();

        if (!$bom) {
            Log::warning('PreorderAutoProduction: BOM auto tidak ditemukan untuk produk preorder', [
                'sales_order_id' => $so->id,
                'product_id'     => $product->id,
                'product_sku'    => $product->sku,
            ]);
            return array_merge($base, ['reason' => "BOM auto tidak ditemukan untuk produk {$product->sku}."]);
        }

        $mainOutput = $bom->outputs->firstWhere('output_type', 'main');
        if (!$mainOutput || abs((float) $mainOutput->qty_per_cycle - 1.0) > 0.0001) {
            Log::error('PreorderAutoProduction: BOM auto preorder dengan qty_per_cycle != 1 (data tidak konsisten)', [
                'bom_id'         => $bom->id,
                'bom_number'     => $bom->bom_number,
                'qty_per_cycle'  => $mainOutput?->qty_per_cycle,
                'sales_order_id' => $so->id,
            ]);
            return array_merge($base, ['reason' => "BOM {$bom->bom_number} tidak valid untuk preorder (qty per siklus harus = 1)."]);
        }

        // Idempotensi BERBASIS QTY, bukan sekadar "OP-nya sudah ada atau belum".
        //
        // Cek lama hanya bertanya ada/tidak ada OP untuk (SO + produk). Karena loop di atas
        // berjalan PER BARIS SO, sedangkan cek-nya per produk, satu produk yang dipesan lewat
        // >1 baris SO (lazim di pesanan marketplace: channel memecah SKU sama jadi beberapa
        // baris) hanya diproduksi sebanyak baris pertama — baris berikutnya diblokir oleh OP
        // yang baru saja dibuat baris pertama. Kasus nyata TP-585249530329007489: 2 baris @1 pcs,
        // OP hanya 1 pcs, sisanya keluar lewat SJ tanpa pernah diproduksi (stok jadi minus).
        //
        // Sekarang: hitung SISA = total qty produk ini di SELURUH baris SO − qty yang sudah
        // direncanakan OP milik SO ini. Karena dihitung ulang dari data setiap kali, semua
        // sumber "sudah direncanakan" ikut otomatis: pemicu yang jalan >1 kali (DP kedua,
        // retry webhook) maupun OP manual yang dibuat tim lebih dulu (kasus SO/2026/06/00013).
        $orderedQty = (float) $so->items
            ->where('product_id', $product->id)
            ->sum(fn ($i) => (float) $i->qty * (float) ($i->conversion_to_base ?: 1));

        $plannedQty = $this->plannedQtyForSalesOrder((int) $so->id, (int) $product->id);
        $remaining  = round($orderedQty - $plannedQty, 4);

        if ($remaining <= 0) {
            return array_merge($base, [
                'reason' => 'Produksi produk ini sudah direncanakan penuh ('
                    . $this->fmtQty($plannedQty) . ' dari ' . $this->fmtQty($orderedQty) . '), dilewati.',
            ]);
        }

        // Produk yang unitnya bisa saling menggantikan: kebutuhan boleh ditutup barang yang
        // SUDAH ADA, tak peduli dulu dibuat untuk siapa. Tanpa ini, sisa produksi pesanan yang
        // batal tak pernah terpakai — tiap pesanan baru memicu OP baru dan unit lamanya
        // mengendap jadi deadstock.
        //
        // Nettingnya GLOBAL per produk, bukan per-SO. Kalau per-SO, dua pesanan yang datang
        // hampir bersamaan sama-sama melihat "stok sisa sudah dipesan orang lain" lalu
        // sama-sama membuat OP — overproduksi satu unit. Dihitung global, pesanan kedua
        // melihat kebutuhannya sudah tertutup OP milik pesanan pertama.
        //
        // Produk yang dibuat mengikuti permintaan pembeli (CS1, CS2, …) TIDAK ikut: SKU-nya
        // cuma wadah, unit di bawahnya bisa beda barang, dan HPP-nya menempel pada OP-nya
        // sendiri. Untuk mereka perilakunya tetap satu pesanan = satu OP.
        if ($product->sharesStockAcrossOrders()) {
            $belumTertutup = $this->uncoveredDemand((int) $product->id, (int) $so->warehouse_id);

            if ($belumTertutup <= 0) {
                return array_merge($base, [
                    'reason' => 'Kebutuhan produk ' . $product->sku
                        . ' sudah tertutup stok yang ada / produksi yang sedang berjalan, dilewati.',
                ]);
            }

            // Jangan memproduksi melebihi kebutuhan SO ini sendiri — sisa kebutuhan pesanan
            // lain diurus saat pemicu pesanan itu berjalan.
            $remaining = min($remaining, $belumTertutup);
        }

        // 1 siklus = 1 unit (BOM preorder wajib qty_per_cycle = 1, sudah divalidasi di atas).
        // Dibulatkan ke ATAS supaya qty pecahan tidak menyisakan kekurangan produksi.
        $cycles = (int) max(1, (int) ceil($remaining - 0.0001));

        $materials = $this->bomService->calculateMaterials($bom->id, $cycles);
        $outputs   = $this->bomService->calculateOutputs($bom->id, $cycles);
        $steps     = $this->bomService->getSteps($bom->id);

        $customerName = $so->customer?->name ?? '-';
        $notes = "Auto-produksi pre-order dari SO {$so->order_number} (Customer: {$customerName}, item baris #{$lineNo}).";
        // Satu produk bisa tersebar di beberapa baris SO → OP ini menutup SISA qty produk
        // tersebut untuk seluruh SO, bukan cuma baris yang sedang diproses. Dicatat di notes
        // supaya tim produksi tidak bingung melihat qty OP > qty baris.
        if ($remaining > (float) $item->qty * (float) ($item->conversion_to_base ?: 1)) {
            $notes .= " Mencakup sisa qty produk {$product->sku} dari seluruh baris SO: "
                . $this->fmtQty($remaining) . ' dari ' . $this->fmtQty($orderedQty) . ' dipesan.';
        }

        try {
            $order = DB::transaction(function () use ($bom, $so, $cycles, $materials, $outputs, $steps, $notes) {
                return $this->orderService->create([
                    'type'            => 'custom',
                    'bom_id'          => $bom->id,
                    'sales_order_id'  => $so->id,
                    'warehouse_id'    => $so->warehouse_id,
                    'production_date' => now()->format('Y-m-d'),
                    'planned_cycles'  => $cycles,
                    'score_type'      => 'auto',
                    'created_via'     => 'auto_preorder',
                    'description'     => $bom->description,
                    'notes'           => $notes,
                    'materials' => array_map(fn($m) => [
                        'product_id'   => $m['product_id'],
                        'qty_required' => $m['qty_required'],
                        'unit'         => $m['unit'] ?? null,
                    ], $materials),
                    'outputs' => array_map(fn($o) => [
                        'product_id'  => $o['product_id'],
                        'qty_planned' => $o['qty_planned'],
                        'output_type' => $o['output_type'],
                        'percentage'  => $o['percentage'],
                    ], $outputs),
                    'steps' => $steps,
                ]);
            });

            // Soft-confirm: preorder langsung 'confirmed' (siap dikerjakan) TANPA konsumsi
            // material sekarang — material biasanya belum dibeli saat DP masuk. Konsumsi FIFO +
            // jurnal WIP dilakukan nanti saat finalisasi produksi (konsumsi tertunda).
            $order->update(['status' => 'confirmed']);
            $statusNote = 'dikonfirmasi (material dikonsumsi saat finalisasi)';

            return array_merge($base, [
                'created'      => true,
                'order_number' => $order->order_number,
                'reason'       => "Order produksi dibuat: {$cycles} siklus — {$statusNote}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('PreorderAutoProduction: gagal membuat order', [
                'sales_order_id' => $so->id,
                'product_id'     => $product->id,
                'bom_id'         => $bom->id,
                'message'        => $e->getMessage(),
            ]);
            return array_merge($base, ['reason' => 'Gagal membuat order: ' . $e->getMessage()]);
        }
    }

    /**
     * Total qty sebuah produk yang SUDAH direncanakan produksi untuk satu SO
     * (semua OP non-cancelled milik SO tsb, apa pun created_via-nya).
     *
     * Penggabungan OP (merge) menyerap output anak ke induk TAPI baris output milik anak tetap
     * ada (lihat ProductionOrderService::merge). Kalau semua output dijumlah mentah, qty anak
     * terhitung dua kali; dan karena induk bisa menyerap anak dari SO LAIN, induk jadi seolah
     * merencanakan lebih banyak dari yang dipesan SO-nya sendiri — cukup untuk membuat sisa
     * qty terbaca 0 dan kekurangan produksi tak pernah terdeteksi. Karena itu output tiap OP
     * dikurangi output anak-anak yang di-merge ke dalamnya; kontribusi anak tetap terhitung
     * lewat barisnya sendiri, di SO-nya masing-masing.
     */
    private function plannedQtyForSalesOrder(int $salesOrderId, int $productId): float
    {
        $orderIds = ProductionOrder::where('sales_order_id', $salesOrderId)
            ->where('status', '!=', 'cancelled')
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        $planned = (float) DB::table('production_order_outputs')
            ->whereIn('production_order_id', $orderIds)
            ->where('output_type', 'main')
            ->where('product_id', $productId)
            ->sum('qty_planned');

        $absorbed = (float) DB::table('production_order_outputs as poo')
            ->join('production_orders as child', 'child.id', '=', 'poo.production_order_id')
            ->whereIn('child.merged_into_id', $orderIds)
            ->where('child.status', '!=', 'cancelled')
            ->where('poo.output_type', 'main')
            ->where('poo.product_id', $productId)
            ->sum('poo.qty_planned');

        return round($planned - $absorbed, 4);
    }

    /**
     * Kebutuhan sebuah produk yang BELUM tertutup apa pun, di satu gudang:
     *
     *     Σ reservasi aktif  −  stok fisik  −  Σ qty rencana OP yang masih berjalan
     *
     * OP `finalized` sengaja TIDAK dihitung: hasilnya sudah masuk stok fisik, jadi
     * menghitungnya lagi berarti mengurangi kebutuhan dua kali dan produksi yang benar-benar
     * perlu tidak akan pernah dibuat.
     *
     * Hasil ≤ 0 berarti semua permintaan sudah ada penutupnya — entah barangnya sudah di rak,
     * atau sedang dikerjakan.
     */
    private function uncoveredDemand(int $productId, int $warehouseId): float
    {
        $diminta = (float) DB::table('stock_reservations')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'active')
            ->sum('qty');

        $stok = (float) DB::table('product_stocks')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('qty_on_hand');

        return round($diminta - $stok - $this->plannedQtyInProgress($productId), 4);
    }

    /**
     * Qty produk ini yang sedang direncanakan seluruh OP berjalan (semua SO).
     *
     * Sama seperti plannedQtyForSalesOrder, output OP anak yang sudah diserap induk lewat
     * penggabungan dikurangkan — barisnya tetap ada di anak, jadi kalau tidak dikurangi
     * qty-nya terhitung dua kali dan kebutuhan tampak lebih tertutup dari kenyataan.
     */
    private function plannedQtyInProgress(int $productId): float
    {
        $orderIds = ProductionOrder::whereNotIn('status', ['cancelled', 'finalized'])->pluck('id');
        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        $planned = (float) DB::table('production_order_outputs')
            ->whereIn('production_order_id', $orderIds)
            ->where('output_type', 'main')
            ->where('product_id', $productId)
            ->sum('qty_planned');

        $absorbed = (float) DB::table('production_order_outputs as poo')
            ->join('production_orders as child', 'child.id', '=', 'poo.production_order_id')
            ->whereIn('child.merged_into_id', $orderIds)
            ->whereNotIn('child.status', ['cancelled', 'finalized'])
            ->where('poo.output_type', 'main')
            ->where('poo.product_id', $productId)
            ->sum('poo.qty_planned');

        return round($planned - $absorbed, 4);
    }

    /** Qty untuk pesan log/notes: buang nol di belakang koma (2.0000 → 2). */
    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
