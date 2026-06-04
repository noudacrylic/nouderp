<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\SalesInvoice;

class MarketplaceSettlementLine extends Model
{
    protected $fillable = [
        'marketplace_settlement_id',
        'order_ref',
        'settlement_date',
        'gross_amount',
        'fee_actual',
        'fee_config',
        'fee_diff',
        'net_amount',
        'sales_invoice_id',
        'is_matched',
        'raw_row',
        'note',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'is_matched' => 'boolean',
        'gross_amount' => 'decimal:2',
        'fee_actual' => 'decimal:2',
        'fee_config' => 'decimal:2',
        'fee_diff' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(MarketplaceSettlement::class, 'marketplace_settlement_id');
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
