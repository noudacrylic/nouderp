<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Link 1 pesanan Jubelio ke 1 Sales Order ERP, dengan flag idempotensi per tahap.
 */
class JubelioOrderLink extends Model
{
    protected $fillable = [
        'jubelio_salesorder_id',
        'jubelio_salesorder_no',
        'sales_order_id',
        'store',
        'last_status',
        'dp_posted',
        'sj_created',
        'invoice_posted',
        'return_created',
        'jubelio_invoice_id',
        'customer_payment_id',
        'last_error',
        // Rantai fulfillment WMS (pick → pack → faktur → resi)
        'j_ready_to_pick',
        'j_picklist_done',
        'j_packed',
        'j_invoice_done',
        'j_invoice_id',
        'awb_requested',
        'tracking_no',
        'shipper',
        'is_instant_courier',
        'cancel_requested',
        'cancel_reason',
        'cancel_requested_at',
        'snap_customer',
        'snap_grand_total',
        'snap_item_count',
        'snap_order_date',
        'j_label_url',
        'j_faktur_url',
        'wms_last_error',
        'wms_completed_at',
        'resi_printed_at',
    ];

    protected $casts = [
        'dp_posted'        => 'boolean',
        'sj_created'       => 'boolean',
        'invoice_posted'   => 'boolean',
        'return_created'   => 'boolean',
        'j_ready_to_pick'  => 'boolean',
        'j_picklist_done'  => 'boolean',
        'j_packed'         => 'boolean',
        'j_invoice_done'   => 'boolean',
        'awb_requested'       => 'boolean',
        'is_instant_courier'  => 'boolean',
        'cancel_requested'    => 'boolean',
        'cancel_requested_at' => 'datetime',
        'snap_grand_total'    => 'float',
        'snap_item_count'     => 'integer',
        'snap_order_date'     => 'date',
        'wms_completed_at'    => 'datetime',
        'resi_printed_at'     => 'datetime',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /** Resi sudah didapat (rantai WMS tuntas). */
    public function isWmsComplete(): bool
    {
        return !empty($this->tracking_no);
    }

    /**
     * Tahap rantai WMS saat ini (untuk badge & resume): belum → picking → packed →
     * invoiced → awb → done. Diturunkan dari flag, bukan kolom status sendiri.
     */
    public function wmsStage(): string
    {
        return match (true) {
            $this->isWmsComplete()   => 'done',
            $this->awb_requested     => 'awb',
            $this->j_invoice_done    => 'invoiced',
            $this->j_packed          => 'packed',
            $this->j_picklist_done   => 'picking',
            $this->j_ready_to_pick   => 'picking',
            default                  => 'belum',
        };
    }

    /** Label tahap WMS untuk ditampilkan. */
    public function wmsStageLabel(): string
    {
        return match ($this->wmsStage()) {
            'done'     => 'Resi terbit',
            'awb'      => 'Minta resi',
            'invoiced' => 'Faktur dibuat',
            'packed'   => 'Selesai packing',
            'picking'  => 'Picking',
            default    => 'Belum diproses',
        };
    }
}
