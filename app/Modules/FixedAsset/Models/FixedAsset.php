<?php

namespace App\Modules\FixedAsset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;

class FixedAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'asset_code',
        'name',
        'description',
        'asset_category_id',
        'warehouse_id',
        'responsible_person',
        'serial_number',
        'acquisition_date',
        'acquisition_cost',
        'salvage_value',
        'useful_life_months',
        'is_depreciable',
        'depreciation_start_date',
        'last_depreciation_date',
        'accumulated_depreciation',
        'current_book_value',
        'months_depreciated',
        'source_type',
        'source_invoice_id',
        'source_invoice_item_id',
        'status',
        'journal_acquisition_id',
        'journal_disposal_id',
        'posted_at',
        'posted_by',
        'disposed_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'depreciation_start_date' => 'date',
        'last_depreciation_date' => 'date',
        'disposed_date' => 'date',
        'is_depreciable' => 'boolean',
        'posted_at' => 'datetime',
        'acquisition_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'current_book_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'months_depreciated' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function acquisitionJournal()
    {
        return $this->belongsTo(Journal::class, 'journal_acquisition_id');
    }

    public function disposalJournal()
    {
        return $this->belongsTo(Journal::class, 'journal_disposal_id');
    }

    public function sourceInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'source_invoice_id');
    }

    public function sourceInvoiceItem()
    {
        return $this->belongsTo(PurchaseInvoiceItem::class, 'source_invoice_item_id');
    }

    public function depreciations()
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    public function transfers()
    {
        return $this->hasMany(FixedAssetTransfer::class);
    }

    public function disposals()
    {
        return $this->hasMany(FixedAssetDisposal::class);
    }

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    public function canBePosted(): bool
    {
        if ($this->status !== 'draft') return false;
        if ((float) $this->acquisition_cost <= 0) return false;
        if ($this->is_depreciable && (int) $this->useful_life_months <= 0) return false;
        return true;
    }

    public function canBeVoided(): bool
    {
        if ($this->status === 'draft') return true;

        if ($this->status === 'active') {
            $hasDep = $this->depreciations()->where('status', 'posted')->exists();
            $hasTransfer = $this->transfers()->where('status', 'posted')->exists();
            $hasDisposal = $this->disposals()->where('status', 'posted')->exists();
            return !$hasDep && !$hasTransfer && !$hasDisposal;
        }

        return false;
    }

    public function canBeDisposed(): bool
    {
        return $this->status === 'active';
    }

    public function monthlyDepreciation(): float
    {
        if (!$this->is_depreciable || (int) $this->useful_life_months <= 0) return 0;
        $base = (float) $this->acquisition_cost - (float) $this->salvage_value;
        if ($base <= 0) return 0;
        return round($base / (int) $this->useful_life_months, 2);
    }

    public function recalculateBookValue(): void
    {
        $this->current_book_value = round((float) $this->acquisition_cost - (float) $this->accumulated_depreciation, 2);
    }
}
