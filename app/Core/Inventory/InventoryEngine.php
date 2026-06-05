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
            if (!$allowNegative && $balance < 0) {
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

            return $balance;
        });
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

            $ledger->qty_in = $qty;
            $ledger->qty_out = 0;
            $ledger->balance = $qty;
            $ledger->save();

            ProductStock::updateOrCreate(
                [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId
                ],
                [
                    'qty_on_hand' => $qty
                ]
            );

            app(FifoService::class)
                ->updateOpeningLayer($productId, $warehouseId, $qty, $cost, $transactionId);
        });
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

            // Reservation update
            $res = StockReservation::where([
                'product_id' => $product,
                'warehouse_id' => $warehouse,
                'sales_order_id' => $soId,
                'status' => 'active'
            ])->first();

            if ($res) {

                $res->qty -= $qty;

                if ($res->qty <= 0) {
                    $res->delete();
                } else {
                    $res->save();
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

            $res = StockReservation::where([
                'product_id'     => $product,
                'warehouse_id'   => $warehouse,
                'sales_order_id' => $soId,
                'status'         => 'active',
            ])->first();

            if ($res) {
                $res->qty -= $qty;
                if ($res->qty <= 0) {
                    $res->delete();
                } else {
                    $res->save();
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
