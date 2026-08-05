<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        'packing_notified_at',
        'cancel_requested',
        'cancel_reason',
        'cancel_requested_at',
        'snap_customer',
        'snap_grand_total',
        'snap_item_count',
        'snap_order_date',
        'mp_due_date',
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
        'packing_notified_at' => 'datetime',
        'cancel_requested'    => 'boolean',
        'cancel_requested_at' => 'datetime',
        'snap_grand_total'    => 'float',
        'snap_item_count'     => 'integer',
        'snap_order_date'     => 'date',
        'mp_due_date'         => 'datetime',
        'wms_completed_at'    => 'datetime',
        'resi_printed_at'     => 'datetime',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * Berapa banyak dari reservasi ERP atas produk $reservedProductId yang sudah punya
     * PASANGAN reservasi di Jubelio atas item $jubelioItemProductId — yaitu bagian yang
     * TIDAK boleh dikurangkan lagi saat mendorong stok, karena Jubelio menahannya sendiri.
     *
     * Kenapa dua parameter produk, bukan satu:
     *   Jubelio menahan stok per ITEM yang benar-benar dipesan pembeli, sedangkan ERP
     *   mereservasi per produk yang benar-benar dipakai. Untuk penjualan biasa keduanya
     *   produk yang sama. Untuk BUNDLE keduanya BERBEDA: pembeli memesan item bundle,
     *   ERP mereservasi komponennya. Karena itu:
     *     - dorong produk biasa P : coveredReservationQty(P, P)
     *     - dorong bundle B       : coveredReservationQty(B, komponen) × qty_per_bundle
     *   Menyamakan keduanya (menganggap "semua reservasi pesanan marketplace sudah ditahan
     *   Jubelio") adalah sumber oversell: order bundle menahan item BUNDLE di Jubelio, tapi
     *   item KOMPONEN di sana tidak tersentuh — kalau reservasi komponen ikut dikecualikan,
     *   komponen tetap ditawarkan penuh dan barang yang sama dijanjikan dua kali.
     *
     * Diukur dari baris pesanan (bukan jumlah reservasi) supaya satu SO yang memuat bundle
     * SEKALIGUS komponennya tetap terhitung tepat: hanya sebanyak baris langsungnya yang
     * dikecualikan, sisanya (bagian dari bundle) tetap dikurangkan.
     *
     * Dibatasi ke SO yang MASIH menahan reservasi aktif atas $reservedProductId — begitu
     * Surat Jalan terbit reservasinya hilang dan stok fisik yang turun, jadi baris pesanan
     * lama tidak boleh terus dipotong.
     */
    public static function coveredReservationQty(int $jubelioItemProductId, int $reservedProductId, ?int $warehouseId = null): float
    {
        return (float) DB::table('sales_order_items as i')
            ->join('jubelio_order_links as j', 'j.sales_order_id', '=', 'i.sales_order_id')
            ->where('i.product_id', $jubelioItemProductId)
            ->whereIn('i.sales_order_id', function ($q) use ($reservedProductId, $warehouseId) {
                $q->select('sales_order_id')
                  ->from('stock_reservations')
                  ->where('product_id', $reservedProductId)
                  ->where('status', 'active');
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            })
            ->sum(DB::raw('i.qty * COALESCE(i.conversion_to_base, 1)'));
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
