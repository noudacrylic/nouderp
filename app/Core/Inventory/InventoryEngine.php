<?php

namespace App\Core\Inventory;

use Illuminate\Support\Facades\DB;
use App\Models\InventoryLedger;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\StockShipment;

class InventoryEngine
{
    /*
    |--------------------------------------------------------------------------
    | AVAILABLE STOCK
    |--------------------------------------------------------------------------
    */

    public function availableStock(int $productId, ?int $warehouseId = null): float
    {
        $balance = $this->latestBalance($productId, $warehouseId);

        $reserved = StockReservation::where('product_id', $productId)
            ->where('status', 'active')
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('qty');

        return (float) ($balance - $reserved);
    }

    /**
     * Saldo stok fisik (ledger balance) tanpa dikurangi reservasi.
     * Dipakai untuk memutuskan apakah pengiriman cukup distok atau harus di-defer.
     */
    public function onHand(int $productId, ?int $warehouseId = null): float
    {
        return $this->latestBalance($productId, $warehouseId);
    }

    /**
     * Sisa kuantitas yang BENAR-BENAR bisa dipenuhi dari FIFO layer (SUM qty_remaining).
     * Normalnya sama dengan onHand(), tapi bila ledger & layer drift (mis. entri manual yg
     * menaikkan ledger tanpa bikin layer), HANYA sebanyak ini yang bisa dikonsumsi Surat Jalan.
     * Dipakai sebagai PLAFON anti-oversell saat push stok ke marketplace: jangan pernah tawarkan
     * lebih dari yang bisa dikirim. Lihat [[nouderp-stocklayers-ledger-drift-sj-stuck]].
     */
    public function fifoRemaining(int $productId, ?int $warehouseId = null): float
    {
        return (float) DB::table('stock_layers')
            ->where('product_id', $productId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('qty_remaining');
    }

    /**
     * Saldo ledger terakhir. Kolom `balance` adalah running-total PER (produk, gudang),
     * jadi untuk lintas-gudang (warehouseId null) TIDAK boleh ambil baris terakhir saja —
     * harus menjumlahkan saldo terakhir tiap gudang.
     */
    private function latestBalance(int $productId, ?int $warehouseId = null): float
    {
        if ($warehouseId) {
            return (float) (InventoryLedger::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->orderByDesc('id')->value('balance') ?? 0);
        }

        $latestIds = InventoryLedger::where('product_id', $productId)
            ->selectRaw('MAX(id) as id')
            ->groupBy('warehouse_id')
            ->pluck('id');

        return (float) (InventoryLedger::whereIn('id', $latestIds)->sum('balance'));
    }

    /*
    |--------------------------------------------------------------------------
    | CORE LEDGER
    |--------------------------------------------------------------------------
    */

    public function ledger(
        int $productId,
        int $warehouseId,
        float $qtyIn,
        float $qtyOut,
        string $type,
        ?string $reference = null,
        ?string $notes = null,
        int $transactionId = 0,
        bool $allowNegative = false
    ): float {

        if (!$warehouseId) {
            throw new \Exception("Warehouse ID tidak boleh null.");
        }

        return DB::transaction(function () use (
            $productId,
            $warehouseId,
            $qtyIn,
            $qtyOut,
            $type,
            $reference,
            $notes,
            $transactionId,
            $allowNegative
        ) {

            $lastBalance = InventoryLedger::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->orderByDesc('id')
                ->value('balance') ?? 0;

            $balance = $lastBalance + $qtyIn - $qtyOut;

            // Saldo minus diizinkan khusus pengiriman partial preorder (COGS ditunda).
            // Stok MASUK murni (qty_out = 0) tidak pernah diblokir: menambah stok mustahil
            // menyebabkan kekurangan. Kalau saldo masih minus setelahnya, itu minus WARISAN
            // (mis. SJ dicetak duluan sebelum produksi selesai) — memblokir stock-in justru
            // mengunci OP/pembelian yang seharusnya memperbaiki minus tersebut.
            $isPureStockIn = $qtyIn > 0 && $qtyOut <= 0;
            if (!$allowNegative && !$isPureStockIn && $balance < 0) {
                throw new \Exception("Stock tidak mencukupi.");
            }

            InventoryLedger::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'transaction_type' => strtolower($type),
                'transaction_id' => $transactionId,
                'qty_in' => $qtyIn,
                'qty_out' => $qtyOut,
                'balance' => $balance
            ]);

            ProductStock::updateOrCreate(
                [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId
                ],
                [
                    'qty_on_hand' => $balance
                ]
            );

            $this->syncProductStockColumn($productId);

            return $balance;
        });
    }

    /**
     * Sinkronkan kolom cache lama `products.stock` = total on-hand semua gudang
     * (SUM product_stocks.qty_on_hand). Kolom ini denormalisasi; sumber kebenaran
     * tetap ledger/product_stocks. Dipanggil tiap kali qty_on_hand berubah agar
     * kolom tak pernah drift lagi (dulu hanya ditulis Stock Opname → sering basi).
     * Pakai query builder langsung (bukan Eloquent) agar tak memicu observer
     * maupun bump products.updated_at yang bisa menandai jubelio_sync_pending.
     */
    private function syncProductStockColumn(int $productId): void
    {
        $total = (float) ProductStock::where('product_id', $productId)->sum('qty_on_hand');
        DB::table('products')->where('id', $productId)->update(['stock' => $total]);
    }

    /*
    |--------------------------------------------------------------------------
    | OPENING BALANCE
    |--------------------------------------------------------------------------
    */

    public function opening($product, $warehouse, $qty, $cost, $transactionId = 0)
    {
        DB::transaction(function () use ($product, $warehouse, $qty, $cost, $transactionId) {

            $this->ledger($product, $warehouse, $qty, 0, 'opening', 'OB', null, $transactionId);

            app(FifoService::class)
                ->createLayer($product, $warehouse, $qty, $cost, 'opening', $transactionId);
        });
    }

    public function updateOpening($productId, $warehouseId, $qty, $cost, $transactionId)
    {
        DB::transaction(function () use ($productId, $warehouseId, $qty, $cost, $transactionId) {

            $ledger = \App\Models\InventoryLedger::where([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'transaction_type' => 'opening',
                'transaction_id' => $transactionId
            ])->first();

            if (!$ledger) {
                throw new \Exception("Opening ledger tidak ditemukan.");
            }

            // Ubah qty saldo awal. Saldo awal boleh diubah berkali-kali.
            $ledger->qty_in = $qty;
            $ledger->qty_out = 0;
            $ledger->save();

            // Sesuaikan layer FIFO saldo awal (qty & harga), pertahankan yang sudah terpakai.
            app(FifoService::class)
                ->updateOpeningLayer($productId, $warehouseId, $qty, $cost, $transactionId);

            // Hitung ulang saldo berjalan SEMUA transaksi produk+gudang ini (kronologis),
            // supaya perubahan saldo awal mengalir ke baris-baris setelahnya & stok akhir.
            $this->recomputeBalances($productId, $warehouseId);
        });
    }

    /**
     * Hitung ulang kolom `balance` semua baris ledger (kronologis) untuk satu produk+gudang,
     * lalu sinkronkan stok akhir ke ProductStock. Dipakai setelah saldo awal diubah.
     */
    public function recomputeBalances($productId, $warehouseId): float
    {
        $rows = \App\Models\InventoryLedger::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $running = 0.0;
        foreach ($rows as $row) {
            $running += (float) $row->qty_in - (float) $row->qty_out;
            if ((float) $row->balance !== $running) {
                $row->balance = $running;
                $row->save();
            }
        }

        ProductStock::updateOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['qty_on_hand' => $running]
        );

        $this->syncProductStockColumn($productId);

        return $running;
    }

    /*
    |--------------------------------------------------------------------------
    | PURCHASE
    |--------------------------------------------------------------------------
    */

    public function purchase($product, $warehouse, $qty, $cost, $reference, $transactionId = 0)
    {
        DB::transaction(function () use ($product, $warehouse, $qty, $cost, $reference, $transactionId) {

            $this->ledger($product, $warehouse, $qty, 0, 'purchase', $reference, null, $transactionId);

            app(FifoService::class)
                ->createLayer($product, $warehouse, $qty, $cost, 'purchase', $transactionId);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RESERVE STOCK (SO)
    |--------------------------------------------------------------------------
    */

    public function reserve($product, $warehouse, $qty, $soId)
    {
        return DB::transaction(function () use ($product, $warehouse, $qty, $soId) {

            // Lock baris stok utk serialisasi cek-lalu-reservasi (anti TOCTOU): tanpa ini
            // dua reservasi konkuren bisa sama-sama lolos cek availability lalu over-reserve.
            ProductStock::where('product_id', $product)
                ->where('warehouse_id', $warehouse)
                ->lockForUpdate()
                ->first();

            $available = $this->availableStock($product, $warehouse);

            if ($qty > $available) {
                throw new \Exception("Stock tidak cukup untuk reservasi.");
            }

            return StockReservation::create([
                'product_id' => $product,
                'warehouse_id' => $warehouse,
                'sales_order_id' => $soId,
                'qty' => $qty,
                'status' => 'active'
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | SHIPMENT (SALES DELIVERY)
    |--------------------------------------------------------------------------
    */

    public function ship($product, $warehouse, $qty, $reference, $deliveryId, $soId)
    {
        $productModel = \App\Core\Inventory\Product::find($product);
        $totalCogs = 0;

        // Jika bundle → kirim komponen
        if ($productModel && $productModel->sale_type === 'bundle') {

            // Prioritaskan BundleComponent
            $components = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $product)->get();
            $qtyField = 'qty';

            if ($components->isEmpty()) {
                // Fallback ke ProductBundle
                $components = \App\Core\Inventory\ProductBundle::where('bundle_product_id', $product)->get();
                $qtyField = 'qty_required';
            }

            foreach ($components as $component) {

                $componentQty = ($component->{$qtyField} ?? 1) * $qty;

                $totalCogs += $this->ship(
                    $component->component_product_id,
                    $warehouse,
                    $componentQty,
                    $reference,
                    $deliveryId,
                    $soId
                );
            }

            return $totalCogs;
        }

        return DB::transaction(function () use ($product, $warehouse, $qty, $reference, $deliveryId, $soId) {

            // Ledger
            $this->ledger($product, $warehouse, 0, $qty, 'sale', $reference, null, $deliveryId);

            // FIFO consume
            $cogs = app(FifoService::class)->consume($product, $warehouse, $qty, 'sales', $deliveryId);

            // Shipment log
            StockShipment::create([
                'product_id' => $product,
                'warehouse_id' => $warehouse,
                'delivery_id' => $deliveryId,
                'qty' => $qty
            ]);

            // Reservation release: kurangi qty terkirim dari reservasi AKTIF SO ini.
            // Penting: satu SO bisa punya BANYAK baris reservasi (1 per line item),
            // jadi harus iterasi lintas-baris sampai qty terkirim habis — bukan hanya
            // baris pertama. Kalau cuma baris pertama, sisa baris nyangkut "active"
            // dan mengganggu hitung stok tersedia (push Jubelio).
            if ($soId) {
                $remainingRelease = (float) $qty;

                $reservations = StockReservation::where([
                    'product_id'     => $product,
                    'warehouse_id'   => $warehouse,
                    'sales_order_id' => $soId,
                    'status'         => 'active',
                ])->orderBy('id')->lockForUpdate()->get();

                foreach ($reservations as $res) {
                    if ($remainingRelease <= 0.00001) {
                        break;
                    }

                    $take = min((float) $res->qty, $remainingRelease);
                    $res->qty = (float) $res->qty - $take;

                    if ($res->qty <= 0.00001) {
                        $res->delete();
                    } else {
                        $res->save();
                    }

                    $remainingRelease -= $take;
                }
            }

            return $cogs;
        });
    }

    /**
     * Pengiriman saat stok belum cukup: hanya gerakkan ledger (boleh minus) tanpa
     * konsumsi FIFO. COGS ditunda (dihitung saat invoice via settleShipmentCogs()).
     * Khusus produk leaf (bukan bundle) — pemanggil yang memutuskan.
     */
    public function shipDeferred($product, $warehouse, $qty, $reference, $deliveryId, $soId): void
    {
        DB::transaction(function () use ($product, $warehouse, $qty, $reference, $deliveryId, $soId) {

            // Ledger OUT, saldo boleh minus.
            $this->ledger($product, $warehouse, 0, $qty, 'sale', $reference, null, $deliveryId, true);

            StockShipment::create([
                'product_id'   => $product,
                'warehouse_id' => $warehouse,
                'delivery_id'  => $deliveryId,
                'qty'          => $qty,
            ]);

            // Release reservasi lintas-baris (lihat catatan di ship()): satu SO bisa
            // punya banyak baris reservasi, jadi kurangi sampai qty terkirim habis.
            if ($soId) {
                $remainingRelease = (float) $qty;

                $reservations = StockReservation::where([
                    'product_id'     => $product,
                    'warehouse_id'   => $warehouse,
                    'sales_order_id' => $soId,
                    'status'         => 'active',
                ])->orderBy('id')->lockForUpdate()->get();

                foreach ($reservations as $res) {
                    if ($remainingRelease <= 0.00001) {
                        break;
                    }

                    $take = min((float) $res->qty, $remainingRelease);
                    $res->qty = (float) $res->qty - $take;

                    if ($res->qty <= 0.00001) {
                        $res->delete();
                    } else {
                        $res->save();
                    }

                    $remainingRelease -= $take;
                }
            }
        });
    }

    /**
     * Hitung COGS untuk pengiriman yang sebelumnya di-defer: konsumsi FIFO dari
     * layer aktual (tanpa menyentuh ledger — ledger sudah dikurangi saat shipDeferred).
     * Melempar bila layer belum cukup (= produksi belum selesai) → guard blok invoice.
     */
    public function settleShipmentCogs($product, $warehouse, $qty, $deliveryId): float
    {
        return app(FifoService::class)->consume($product, $warehouse, $qty, 'sales', $deliveryId);
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSFER
    |--------------------------------------------------------------------------
    */

    public function transfer($product, $fromWh, $toWh, $qty, $reference, $transactionId = 0)
    {
        DB::transaction(function () use ($product, $fromWh, $toWh, $qty, $reference, $transactionId) {

            $this->ledger($product, $fromWh, 0, $qty, 'transfer_out', $reference, null, $transactionId);

            $this->ledger($product, $toWh, $qty, 0, 'transfer_in', $reference, null, $transactionId);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ADJUSTMENT
    |--------------------------------------------------------------------------
    */

    public function adjustment($product, $warehouse, $qty, $notes = null, $transactionId = 0)
    {
        DB::transaction(function () use ($product, $warehouse, $qty, $notes, $transactionId) {

            if ($qty > 0) {

                $this->ledger($product, $warehouse, $qty, 0, 'adjustment_in', null, $notes, $transactionId);

            } else {

                $this->ledger($product, $warehouse, 0, abs($qty), 'adjustment_out', null, $notes, $transactionId);

                app(FifoService::class)
                    ->consume($product, $warehouse, abs($qty), 'adjustment_out', $transactionId);
            }
        });
    }
}
