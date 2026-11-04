<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Barang datang: seluruh sisa PO langsung jadi faktur posted, stok masuk, dan DP terpakai.
 * Barang kurang/rusak tetap diselesaikan; koreksinya lewat retur dari faktur ini.
 */
class SelesaikanPesananService
{
    public function __construct(private PurchaseInvoiceService $faktur, private PurchaseInvoicePostingService $posting) {}

    public function selesaikan(PurchaseOrder $po): PurchaseInvoice
    {
        $po->loadMissing('items');
        $sisa = $po->items->filter(fn ($i) => (float) $i->qty > (float) $i->qty_invoiced);
        if ($sisa->isEmpty()) throw new \DomainException("Semua barang {$po->po_number} sudah difakturkan.");

        // Posting gagal = draftnya ikut batal, supaya tak ada faktur setengah jadi.
        return DB::transaction(function () use ($po, $sisa) {
            $invoice = $this->faktur->createDraft([
                'supplier_id'           => $po->supplier_id,
                'warehouse_id'          => $po->warehouse_id,
                'purchase_order_id'     => $po->id,
                'invoice_date'          => today()->toDateString(),
                'ppn_percent'           => $po->ppn_percent,
                'global_discount_type'  => $po->global_discount_type,
                'global_discount_value' => $po->global_discount_value,
                'items'                 => $sisa->map(fn ($i) => [
                    'purchase_order_item_id' => $i->id,
                    'product_id'             => $i->product_id,
                    'description'            => $i->description,
                    'qty'                    => (float) $i->qty - (float) $i->qty_invoiced,
                    'unit'                   => $i->unit,
                    'price'                  => $i->price,
                    'discount_type'          => $i->discount_type ?? 'nominal',
                    'discount_value'         => $i->discount_value ?? 0,
                    'is_asset'               => $i->is_asset,
                    'asset_category_id'      => $i->asset_category_id,
                    'asset_name'             => $i->asset_name,
                ])->values()->all(),
            ]);
            $this->posting->post($invoice);

            return $invoice->refresh();
        });
    }
}
