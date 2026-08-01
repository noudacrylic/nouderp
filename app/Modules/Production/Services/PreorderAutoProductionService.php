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

    /** Qty untuk pesan log/notes: buang nol di belakang koma (2.0000 → 2). */
    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
