<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rincian potongan marketplace satu pesanan (dari Jubelio atau laporan penghasilan marketplace). */
class MarketplaceOrderFee extends Model
{
    protected $fillable = [
        'sales_order_id', 'kotor', 'admin', 'admin_rincian', 'affiliate', 'pajak', 'lain',
        'saldo_masuk', 'sumber', 'final', 'pajak_asli', 'diambil_at',
    ];

    protected $casts = [
        'kotor'         => 'float',
        'admin'         => 'float',
        'admin_rincian' => 'array',
        'affiliate'     => 'float',
        'pajak'         => 'float',
        'lain'          => 'float',
        'saldo_masuk'   => 'float',
        'final'         => 'boolean',
        'pajak_asli'    => 'boolean',
        'diambil_at'    => 'datetime',
    ];
}
