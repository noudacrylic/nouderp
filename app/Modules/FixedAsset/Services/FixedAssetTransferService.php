<?php

namespace App\Modules\FixedAsset\Services;

use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Models\FixedAssetTransfer;
use Illuminate\Support\Facades\DB;
use DomainException;

class FixedAssetTransferService
{
    public function generateNumber(): string
    {
        $prefix = 'FAT/' . now()->format('Y/m') . '/';
        $latest = FixedAssetTransfer::where('transfer_number', 'LIKE', $prefix . '%')
            ->orderByDesc('transfer_number')
            ->value('transfer_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function createDraft(array $data): FixedAssetTransfer
    {
        return DB::transaction(function () use ($data) {
            $asset = FixedAsset::findOrFail($data['fixed_asset_id']);
            if ($asset->status !== 'active') {
                throw new DomainException('Hanya aset aktif yang bisa di-transfer.');
            }
            if ((int) $data['to_warehouse_id'] === (int) $asset->warehouse_id) {
                throw new DomainException('Gudang tujuan sama dengan gudang asal.');
            }

            return FixedAssetTransfer::create([
                'transfer_number' => $data['transfer_number'] ?? $this->generateNumber(),
                'fixed_asset_id' => $asset->id,
                'from_warehouse_id' => $asset->warehouse_id,
                'to_warehouse_id' => $data['to_warehouse_id'],
                'transfer_date' => $data['transfer_date'],
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $data['created_by'] ?? null,
            ]);
        });
    }

    public function update(FixedAssetTransfer $tr, array $data): FixedAssetTransfer
    {
        if ($tr->status !== 'draft') {
            throw new DomainException('Hanya transfer draft yang bisa diedit.');
        }

        $tr->fill([
            'to_warehouse_id' => $data['to_warehouse_id'] ?? $tr->to_warehouse_id,
            'transfer_date' => $data['transfer_date'] ?? $tr->transfer_date,
            'notes' => $data['notes'] ?? $tr->notes,
        ]);
        $tr->save();
        return $tr;
    }

    public function post(FixedAssetTransfer $tr): FixedAssetTransfer
    {
        if ($tr->status !== 'draft') {
            throw new DomainException('Hanya transfer draft yang bisa di-post.');
        }

        return DB::transaction(function () use ($tr) {
            $asset = FixedAsset::findOrFail($tr->fixed_asset_id);
            if ($asset->status !== 'active') {
                throw new DomainException('Aset tidak aktif.');
            }

            $tr->from_warehouse_id = $asset->warehouse_id;
            $asset->warehouse_id = $tr->to_warehouse_id;
            $asset->save();

            $tr->status = 'posted';
            $tr->posted_at = now();
            $tr->save();

            return $tr;
        });
    }

    public function void(FixedAssetTransfer $tr): FixedAssetTransfer
    {
        if (!$tr->canBeVoided()) {
            throw new DomainException('Transfer tidak bisa di-void: ada transfer berikutnya yang sudah posted, void itu dulu.');
        }

        return DB::transaction(function () use ($tr) {
            $asset = FixedAsset::findOrFail($tr->fixed_asset_id);

            // Kembalikan asset ke from_warehouse_id (warehouse asal sebelum transfer ini)
            $asset->warehouse_id = $tr->from_warehouse_id;
            $asset->save();

            $tr->status = 'void';
            $tr->voided_at = now();
            $tr->save();

            return $tr;
        });
    }

    public function destroy(FixedAssetTransfer $tr): void
    {
        if ($tr->status !== 'draft') {
            throw new DomainException('Hanya transfer draft yang bisa dihapus.');
        }
        $tr->delete();
    }
}
