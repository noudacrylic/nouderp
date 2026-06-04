<?php

namespace App\Modules\FixedAsset\Services;

use App\Modules\FixedAsset\Models\AssetCategory;
use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class FixedAssetAcquisitionService
{
    public function __construct(
        protected FixedAssetService $assetService
    ) {}

    /**
     * Dipanggil dari PurchaseInvoicePostingService::post() setelah journal saved.
     * Tiap baris invoice dengan is_asset=true → buat N record FixedAsset (per qty).
     * Jurnal sudah dibuat oleh posting service; di sini hanya create master draft.
     */
    public function createAssetsFromInvoice(PurchaseInvoice $invoice): array
    {
        $invoice->load(['items.assetCategory']);
        $created = [];

        DB::transaction(function () use ($invoice, &$created) {
            $assetItems = $invoice->items->where('is_asset', true);

            foreach ($assetItems as $item) {
                if (!$item->asset_category_id) {
                    throw new DomainException('Baris aset wajib punya kategori.');
                }
                if (!$item->asset_name) {
                    throw new DomainException('Baris aset wajib punya nama aset.');
                }

                $cat = $item->assetCategory ?? AssetCategory::findOrFail($item->asset_category_id);
                $qty = (int) $item->qty;
                if ($qty < 1) {
                    throw new DomainException('Qty aset harus >= 1.');
                }

                // final_unit_cost sudah include landed cost share & global discount share (dihitung di PurchaseInvoiceService).
                $unitCost = round((float) $item->final_unit_cost, 2);

                for ($i = 1; $i <= $qty; $i++) {
                    $asset = FixedAsset::create([
                        'asset_code' => $this->assetService->generateAssetCode($cat->id, Carbon::parse($invoice->invoice_date)),
                        'name' => $qty > 1 ? $item->asset_name . ' #' . $i : $item->asset_name,
                        'description' => $item->description,
                        'asset_category_id' => $cat->id,
                        'warehouse_id' => $invoice->warehouse_id,
                        'acquisition_date' => $invoice->invoice_date,
                        'acquisition_cost' => $unitCost,
                        'salvage_value' => round($unitCost * ((float) $cat->default_salvage_value_percent) / 100, 2),
                        'useful_life_months' => $cat->is_depreciable_default ? $cat->default_useful_life_months : null,
                        'is_depreciable' => $cat->is_depreciable_default,
                        'accumulated_depreciation' => 0,
                        'current_book_value' => $unitCost,
                        'months_depreciated' => 0,
                        'source_type' => 'purchase',
                        'source_invoice_id' => $invoice->id,
                        'source_invoice_item_id' => $item->id,
                        'status' => 'draft',
                    ]);
                    $created[] = $asset;
                }
            }
        });

        return $created;
    }

    /**
     * Dipanggil sebelum void invoice. Jika ada aset terkait yang sudah aktif dengan riwayat,
     * tolak void. Selain itu, set aset jadi voided.
     */
    public function revertAssetsForInvoice(PurchaseInvoice $invoice): void
    {
        $assets = FixedAsset::where('source_invoice_id', $invoice->id)
            ->whereNotIn('status', ['voided'])
            ->get();

        if ($assets->isEmpty()) return;

        // Block kalau ada aset active yang punya riwayat
        foreach ($assets as $asset) {
            if ($asset->status === 'active') {
                $hasDep = $asset->depreciations()->where('status', 'posted')->exists();
                $hasTransfer = $asset->transfers()->where('status', 'posted')->exists();
                $hasDisposal = $asset->disposals()->where('status', 'posted')->exists();
                if ($hasDep || $hasTransfer || $hasDisposal) {
                    throw new DomainException(
                        "Invoice tidak bisa di-void: aset {$asset->asset_code} sudah memiliki riwayat (penyusutan/transfer/disposisi). Disposisi atau void riwayat tersebut dulu."
                    );
                }
            }
        }

        DB::transaction(function () use ($assets) {
            foreach ($assets as $asset) {
                $asset->status = 'voided';
                $asset->save();
            }
        });
    }
}
