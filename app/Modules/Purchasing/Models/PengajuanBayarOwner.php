<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Pembelian yang pembayarannya diserahkan ke owner: PO (DP dulu) atau faktur langsung. */
class PengajuanBayarOwner extends Model
{
    protected $table = 'pengajuan_bayar_owner';

    protected $fillable = ['purchase_order_id', 'purchase_invoice_id', 'diajukan_oleh', 'tempo'];

    protected $casts = ['tempo' => 'boolean'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function pengaju()
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }
}
