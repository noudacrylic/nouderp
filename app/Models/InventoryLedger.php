<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\StockLayer;
use App\Modules\Purchasing\Models\PurchaseReturnItem;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;

class InventoryLedger extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'transaction_type',
        'transaction_id',
        'qty_in',
        'qty_out',
        'balance'
    ];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class);
    }

    /**
     * HPP per unit untuk row ini, di-derive dari FIFO layer / cost layer
     * berdasarkan transaction_type. Null kalau tidak applicable / tidak ada data.
     */
    public function getHppPerUnitAttribute(): ?float
    {
        return match ($this->transaction_type) {
            'opening', 'purchase' => $this->layerCost($this->transaction_type),
            // Hasil produksi (stock-in) — HPP dari StockLayer yang dibuat saat finalize
            'production_order'    => $this->layerCost('production_order'),
            // Konsumsi keluar (FIFO consume) — rata-rata berbobot dari cost layer
            'sale'                => $this->consumeAvgCost('sales'),
            'production_material' => $this->consumeAvgCost('production_material'),
            'purchase_return',
            'purchase_return_void' => $this->returnItemCost(),
            'purchase_void'       => $this->invoiceItemCost(),
            default               => null,
        };
    }

    public function getHppTotalAttribute(): ?float
    {
        $unit = $this->hpp_per_unit;
        if ($unit === null) return null;
        $qty = (float) ($this->qty_in > 0 ? $this->qty_in : $this->qty_out);
        return round($unit * $qty, 2);
    }

    protected function layerCost(string $sourceType): ?float
    {
        $cost = StockLayer::where('product_id', $this->product_id)
            ->where('warehouse_id', $this->warehouse_id)
            ->where('source_type', $sourceType)
            ->where('source_id', $this->transaction_id)
            ->value('unit_cost');
        return $cost !== null ? (float) $cost : null;
    }

    /**
     * Rata-rata HPP berbobot untuk transaksi keluar (FIFO consume),
     * di-derive dari cost layer per reference_type (mis. 'sales', 'production_material').
     */
    protected function consumeAvgCost(string $referenceType): ?float
    {
        $rows = InventoryCostLayer::where('product_id', $this->product_id)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $this->transaction_id)
            ->where('qty_out', '>', 0)
            ->get(['qty_out', 'unit_cost']);
        if ($rows->isEmpty()) return null;
        $totalQty = $rows->sum('qty_out');
        if ($totalQty <= 0) return null;
        $totalCost = $rows->sum(fn($r) => (float) $r->qty_out * (float) $r->unit_cost);
        return round($totalCost / $totalQty, 4);
    }

    protected function returnItemCost(): ?float
    {
        $cost = PurchaseReturnItem::where('purchase_return_id', $this->transaction_id)
            ->where('product_id', $this->product_id)
            ->value('unit_cost');
        return $cost !== null ? (float) $cost : null;
    }

    protected function invoiceItemCost(): ?float
    {
        $cost = PurchaseInvoiceItem::where('purchase_invoice_id', $this->transaction_id)
            ->where('product_id', $this->product_id)
            ->value('final_unit_cost');
        return $cost !== null ? (float) $cost : null;
    }
}
