<?php

namespace App\Modules\Production\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoProductionService
{
    public function __construct(
        private BomService $bomService,
        private ProductionOrderService $orderService,
    ) {}

    /**
     * Scan semua BOM dengan auto_production = true, dan buatkan order produksi
     * untuk yang produk utamanya sedang menipis dan belum punya produksi aktif.
     *
     * Return: list result per BOM untuk reporting/logging.
     *   [['bom_id' => X, 'bom_number' => 'BOM/...', 'created' => bool, 'order_number' => 'OP/...', 'reason' => '...'], ...]
     *
     * $simulasi = true → semua pemeriksaan jalan, tapi OP TIDAK dibuat; BOM yang akan dibuatkan
     * OP ditandai 'simulasi' => true. Dipakai mode Bayangan skill Karyawan Virtual.
     */
    public function runAll(bool $simulasi = false): array
    {
        $boms = Bom::with(['outputs.product'])
            ->where('auto_production', true)
            ->get();

        $results = [];

        foreach ($boms as $bom) {
            $results[] = $this->runForBom($bom, $simulasi);
        }

        return $results;
    }

    /**
     * Evaluasi 1 BOM: jika low stock + tidak ada produksi aktif, buat order otomatis.
     */
    public function runForBom(Bom $bom, bool $simulasi = false): array
    {
        $base = [
            'bom_id'     => $bom->id,
            'bom_number' => $bom->bom_number,
            'bom_name'   => $bom->name,
            'created'    => false,
            'order_number' => null,
            'reason'     => null,
            'simulasi'   => false,
        ];

        if (!$bom->auto_production) {
            return array_merge($base, ['reason' => 'Auto-produksi tidak aktif untuk BOM ini.']);
        }

        $bom->loadMissing('outputs.product');
        $mainOutput = $bom->outputs->firstWhere('output_type', 'main');

        if (!$mainOutput || !$mainOutput->product) {
            return array_merge($base, ['reason' => 'BOM tidak memiliki output utama dengan produk valid.']);
        }

        $product = $mainOutput->product;

        // Penentu ready vs preorder = sale_type produk utama. Produk preorder TIDAK
        // diproduksi berdasarkan stok menipis — OP-nya hanya dibuat saat ada pesanan
        // (DP di-post) via PreorderAutoProductionService. Scanner stok ini khusus produk ready.
        if ($product->sale_type === 'preorder') {
            return array_merge($base, ['reason' => "Produk {$product->sku} preorder — OP dibuat saat ada pesanan (DP), bukan dari stok menipis."]);
        }

        $qtyOnHand = $this->stokTersedia($product);
        $minStock  = $product->min_stock !== null ? (float) $product->min_stock : null;

        // Trigger jika: stok habis/minus (apa pun nilai min_stock), atau menipis (qty <= min_stock yang valid).
        $isEmpty   = $qtyOnHand <= 0;
        $isLow     = $minStock !== null && $minStock > 0 && $qtyOnHand <= $minStock;

        if (!$isEmpty && !$isLow) {
            $minLabel = $minStock !== null && $minStock > 0
                ? "min {$minStock}"
                : "min_stock belum di-set";
            return array_merge($base, ['reason' => "Stok {$product->sku} ({$qtyOnHand}) masih aman ({$minLabel})."]);
        }

        $hasActive = $this->adaProduksiAktif($product);

        if ($hasActive) {
            return array_merge($base, ['reason' => "Sudah ada produksi aktif untuk produk {$product->sku}."]);
        }

        $warehouse = $this->gudangProduksi();

        if (!$warehouse) {
            return array_merge($base, ['reason' => 'Tidak ada gudang aktif untuk dipakai.']);
        }

        // BOM alternatif (urutan_pakai): yang dipakai bisa berbeda dari BOM auto yang diperiksa.
        // bom_id hasil tetap BOM auto — kunci usulan & cek ulang saat disetujui memilih lagi.
        [$pakai, $catatanBom] = $this->pilihBom($bom, $warehouse);
        if ($catatanBom) {
            $base['bom_number'] = $pakai->bom_number;
            $base['bom_name']   = $pakai->name;
        }
        $bomNote = $catatanBom ? " BOM: {$catatanBom}." : '';

        $cycles = (int) max(1, (int) ($pakai->typical_cycles ?? 1));

        $materials = $this->bomService->calculateMaterials($pakai->id, $cycles);
        $outputs   = $this->bomService->calculateOutputs($pakai->id, $cycles);
        $steps     = $this->bomService->getSteps($pakai->id);

        $triggerLabel = match(true) {
            $qtyOnHand < 0  => "stok minus {$qtyOnHand}",
            $qtyOnHand == 0 => 'stok habis',
            default         => "stok {$qtyOnHand} <= min {$minStock}",
        };

        if ($simulasi) {
            return array_merge($base, [
                'simulasi' => true,
                'reason'   => "Akan membuat order produksi: {$cycles} siklus ({$triggerLabel}).{$bomNote}",
            ]);
        }

        try {
            $order = DB::transaction(function () use ($pakai, $cycles, $warehouse, $materials, $outputs, $steps, $product, $triggerLabel, $bomNote) {
                return $this->orderService->create([
                    'type'            => 'ready_stock',
                    'bom_id'          => $pakai->id,
                    'warehouse_id'    => $warehouse->id,
                    'production_date' => now()->format('Y-m-d'),
                    'planned_cycles'  => $cycles,
                    'score_type'      => 'auto',
                    'created_via'     => 'auto',
                    'description'     => $pakai->description,
                    'notes'           => "Order otomatis dari BOM {$pakai->bom_number} ({$product->sku}: {$triggerLabel}).{$bomNote}",
                    'materials'       => array_map(fn($m) => [
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

            // Langsung konfirmasi: consume material dari stok + jurnal WIP, status menjadi 'confirmed'
            // sehingga operator bisa langsung mulai. Kalau material tidak cukup, order tetap draft.
            try {
                $this->orderService->confirm($order->id);
                $statusNote = 'dikonfirmasi, siap dikerjakan';
            } catch (\Throwable $confirmError) {
                Log::warning('AutoProductionService: order dibuat tapi gagal dikonfirmasi otomatis', [
                    'order_id' => $order->id,
                    'message'  => $confirmError->getMessage(),
                ]);
                $statusNote = 'tetap draft (konfirmasi otomatis gagal: ' . $confirmError->getMessage() . ')';
            }

            return array_merge($base, [
                'created'      => true,
                'order_number' => $order->order_number,
                'reason'       => "Order produksi dibuat: {$cycles} siklus ({$triggerLabel}) — {$statusNote}.{$bomNote}",
            ]);
        } catch (\Throwable $e) {
            Log::error('AutoProductionService gagal membuat order', [
                'bom_id'  => $bom->id,
                'message' => $e->getMessage(),
            ]);
            return array_merge($base, ['reason' => 'Gagal membuat order: ' . $e->getMessage()]);
        }
    }

    /**
     * Stok tersedia produk jadi — dasar pemicu "stok menipis" (dipakai juga skill NM dari BNM).
     */
    public function stokTersedia(Product $product): float
    {
        // Hanya hitung stok di gudang jual (is_sellable = 1). Gudang non-jual seperti
        // "Cadangan" sengaja DIKECUALIKAN: isinya buffer untuk saat pesanan rame dan
        // tidak boleh menutupi kondisi menipis di gudang operasional. Dengan begitu auto
        // tetap jalan begitu gudang jual menipis, sementara stok cadangan tetap tersimpan.
        //
        // Pakai stok TERSEDIA, bukan on-hand mentah, agar SELARAS dengan kartu "Stok
        // Menipis" di dashboard & kolom "Tersedia" di halaman Stok:
        //   tersedia = Σ on_hand gudang sellable − reservasi aktif + stok preorder.
        // Tanpa mengurangi reservasi, unit yang sudah di-booking pesanan (akan keluar)
        // menutupi kondisi menipis sehingga OP otomatis tak pernah terbentuk walau
        // dashboard sudah menandai produk itu menipis.
        $onHand = (float) ProductStock::where('product_id', $product->id)
            ->whereHas('warehouse', fn ($q) => $q->where('is_sellable', 1))
            ->sum('qty_on_hand');
        $reserved = (float) \App\Core\Inventory\StockReservation::where('product_id', $product->id)
            ->where('status', 'active')
            ->sum('qty');
        return $onHand - $reserved + (float) ($product->preorder_stock ?? 0);
    }

    /**
     * Sudah ada OP berjalan yang menghasilkan produk ini sebagai output utama?
     */
    public function adaProduksiAktif(Product $product): bool
    {
        return $this->jumlahProduksiAktif($product) > 0;
    }

    /** Berapa OP berjalan yang menghasilkan produk ini sebagai output utama. */
    public function jumlahProduksiAktif(Product $product): int
    {
        // Anti-duplikat: cek produksi aktif untuk produk utama yang sama.
        // KECUALIKAN tipe 'repair' (Perbaikan): OP perbaikan hanya memulihkan 1 unit
        // retur/garansi (bukan mengisi ulang stok jual), jadi tidak boleh menghalangi
        // auto-produksi. Hanya produksi asli (ready_stock/custom) yang menambah stok
        // jual yang dianggap duplikat.
        //
        // KECUALIKAN juga status 'merged': task itu sudah diserap ke task induk dan tidak
        // dikerjakan sendiri lagi — induknyalah yang (kalau memang masih jalan) muncul di
        // pengecekan ini. Tanpa pengecualian ini satu task gabungan memblokir auto-produksi
        // produknya SELAMANYA, sehingga stok habis tapi tidak ada OP baru yang terbentuk.
        return ProductionOrder::whereNotIn('status', ['finalized', 'cancelled', 'merged'])
            ->whereNotIn('type', ProductionOrder::REPAIR_TYPES)
            ->whereHas('outputs', function ($q) use ($product) {
                $q->where('product_id', $product->id)
                  ->where('output_type', 'main');
            })
            ->count();
    }

    /**
     * Pilih BOM yang dipakai dari BOM alternatif produk utamanya: urutan_pakai terkecil yang
     * bahannya cukup di gudang produksi. Alternatif = BOM berproduk utama sama yang urutan
     * pakainya diisi, ditambah $bom sendiri. Tanpa alternatif → $bom apa adanya (perilaku lama).
     * Bila semua alternatif kekurangan bahan → urutan pertama (pemanggil memutuskan lanjut/lewat).
     *
     * @return array{0: Bom, 1: ?string, 2: bool}  [BOM terpilih, catatan pilihan (null bila tanpa alternatif), bahan cukup]
     */
    public function pilihBom(Bom $bom, Warehouse $gudang): array
    {
        $utamaId = $bom->outputs()->where('output_type', 'main')->value('product_id');
        $calon = Bom::with('outputs.product')
            ->where(fn ($q) => $q->whereNotNull('urutan_pakai')->orWhere('id', $bom->id))
            ->whereHas('outputs', fn ($q) => $q->where('output_type', 'main')->where('product_id', $utamaId))
            ->get()
            ->sortBy(fn (Bom $b) => [$b->urutan_pakai ?? PHP_INT_MAX, $b->id])
            ->values();

        if ($calon->count() <= 1) {
            return [$bom, null, $this->bahanKurang($bom, $gudang) === null];
        }

        $lewat = [];
        foreach ($calon as $b) {
            $kurang = $this->bahanKurang($b, $gudang);
            if ($kurang === null) {
                $catatan = "pakai {$b->bom_number}" . ($b->urutan_pakai ? " (urutan {$b->urutan_pakai})" : '')
                    . ($lewat ? ' — ' . implode('; ', $lewat) : ', bahan cukup');
                return [$b, $catatan, true];
            }
            $lewat[] = "{$b->bom_number} dilewati: {$kurang}";
        }

        $b = $calon->first();
        return [$b, "bahan semua BOM alternatif kurang — {$b->bom_number} tetap dipakai (" . implode('; ', $lewat) . ')', false];
    }

    /** null = bahan cukup; selain itu ringkasan yang kurang (stok gudang produksi − reservasi). */
    public function bahanKurang(Bom $bom, Warehouse $gudang): ?string
    {
        $kurang = [];
        foreach ($this->bomService->calculateMaterials($bom->id, max(1, (int) ($bom->typical_cycles ?? 1))) as $m) {
            $ada = (float) ProductStock::where('product_id', $m['product_id'])->where('warehouse_id', $gudang->id)->sum('qty_on_hand')
                - (float) \App\Core\Inventory\StockReservation::where('product_id', $m['product_id'])->where('warehouse_id', $gudang->id)->where('status', 'active')->sum('qty');
            if ($ada + 1e-9 < (float) $m['qty_required']) {
                $kurang[] = "{$m['product_sku']} butuh " . (float) $m['qty_required'] . ', ada ' . max(0, $ada);
            }
        }
        return $kurang ? implode('; ', $kurang) : null;
    }

    /** Gudang tempat OP otomatis dibuat (dan bahannya diambil saat konfirmasi). */
    public function gudangProduksi(): ?Warehouse
    {
        return Warehouse::query()
            ->where('is_active', 1)
            ->orderByRaw("CASE WHEN LOWER(name) LIKE '%utama%' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }
}
