<?php

namespace App\Modules\FixedAsset\Services;

use App\Modules\FixedAsset\Models\AssetCategory;
use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Models\FixedAssetSetting;
use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class FixedAssetService
{
    public function __construct(
        protected JournalPostingService $journalPostingService
    ) {}

    /**
     * Generate kode aset format FA/{prefix}/{Y}/{NNNN}, counter per (kategori, tahun).
     * Pakai MAX nomor existing — bukan COUNT — supaya aman dari hapus / soft-delete.
     */
    public function generateAssetCode(int $categoryId, ?Carbon $when = null): string
    {
        $when = $when ?? now();
        $cat = AssetCategory::findOrFail($categoryId);
        $prefix = 'FA/' . strtoupper($cat->code_prefix) . '/' . $when->format('Y') . '/';

        $latest = FixedAsset::withTrashed()
            ->where('asset_code', 'LIKE', $prefix . '%')
            ->orderByDesc('asset_code')
            ->value('asset_code');

        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function createDraft(array $data): FixedAsset
    {
        return DB::transaction(function () use ($data) {
            $cat = AssetCategory::findOrFail($data['asset_category_id']);

            $cost = (float) ($data['acquisition_cost'] ?? 0);
            $isDepreciable = $data['is_depreciable'] ?? $cat->is_depreciable_default;
            $life = $isDepreciable ? ($data['useful_life_months'] ?? null) : null;
            $salvage = (float) ($data['salvage_value'] ?? 0);

            $computed = $this->computeDepreciationFields(
                acquisitionDate: $data['acquisition_date'],
                explicitStart: $data['depreciation_start_date'] ?? null,
                isDepreciable: (bool) $isDepreciable,
                usefulLifeMonths: $life,
                cost: $cost,
                salvage: $salvage,
            );

            $asset = FixedAsset::create([
                'asset_code' => $data['asset_code'] ?? $this->generateAssetCode($cat->id, Carbon::parse($data['acquisition_date'])),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'asset_category_id' => $cat->id,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'responsible_person' => $data['responsible_person'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'acquisition_date' => $data['acquisition_date'],
                'acquisition_cost' => $cost,
                'salvage_value' => $salvage,
                'useful_life_months' => $life,
                'is_depreciable' => $isDepreciable,
                'depreciation_start_date' => $computed['depreciation_start_date'],
                'last_depreciation_date' => $computed['last_depreciation_date'],
                'accumulated_depreciation' => $computed['accumulated_depreciation'],
                'current_book_value' => round($cost - $computed['accumulated_depreciation'], 2),
                'months_depreciated' => $computed['months_depreciated'],
                'source_type' => 'manual',
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $asset;
        });
    }

    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        if (!$asset->canBeEdited()) {
            throw new DomainException('Hanya aset draft yang bisa diedit.');
        }

        return DB::transaction(function () use ($asset, $data) {
            $cat = AssetCategory::findOrFail($data['asset_category_id'] ?? $asset->asset_category_id);

            // Harga perolehan aset dari faktur bersifat otoritatif dari invoice (field readonly di form).
            // Abaikan nilai dari client supaya tidak bisa berubah/drift dari sini.
            $cost = $asset->source_type === 'purchase'
                ? (float) $asset->acquisition_cost
                : (float) ($data['acquisition_cost'] ?? $asset->acquisition_cost);
            $isDepreciable = array_key_exists('is_depreciable', $data) ? (bool) $data['is_depreciable'] : $asset->is_depreciable;
            $life = $isDepreciable ? ($data['useful_life_months'] ?? $asset->useful_life_months) : null;
            $salvage = (float) ($data['salvage_value'] ?? $asset->salvage_value);
            $acqDate = $data['acquisition_date'] ?? $asset->acquisition_date;

            $computed = $this->computeDepreciationFields(
                acquisitionDate: $acqDate,
                explicitStart: $data['depreciation_start_date'] ?? null,
                isDepreciable: $isDepreciable,
                usefulLifeMonths: $life,
                cost: $cost,
                salvage: $salvage,
            );

            $asset->fill([
                'name' => $data['name'] ?? $asset->name,
                'description' => $data['description'] ?? $asset->description,
                'asset_category_id' => $cat->id,
                'warehouse_id' => $data['warehouse_id'] ?? $asset->warehouse_id,
                'responsible_person' => $data['responsible_person'] ?? $asset->responsible_person,
                'serial_number' => $data['serial_number'] ?? $asset->serial_number,
                'acquisition_date' => $acqDate,
                'acquisition_cost' => $cost,
                'salvage_value' => $salvage,
                'useful_life_months' => $life,
                'is_depreciable' => $isDepreciable,
                'depreciation_start_date' => $computed['depreciation_start_date'],
                'last_depreciation_date' => $computed['last_depreciation_date'],
                'accumulated_depreciation' => $computed['accumulated_depreciation'],
                'current_book_value' => round($cost - $computed['accumulated_depreciation'], 2),
                'months_depreciated' => $computed['months_depreciated'],
                'notes' => $data['notes'] ?? $asset->notes,
            ]);
            $asset->save();

            return $asset;
        });
    }

    /**
     * Hitung ulang field tracking penyusutan dari input user.
     * Source of truth: acquisition_date (atau depreciation_start_date kalau di-override) + useful_life + cost + salvage.
     * Output:
     *  - depreciation_start_date: half-month rule dari acquisition_date kalau tidak di-override
     *  - months_depreciated: jumlah bulan kalender dari start s/d hari ini, capped di useful_life
     *  - accumulated_depreciation: straight-line × months_depreciated, capped di (cost - salvage)
     *  - last_depreciation_date: end-of-month bulan terakhir yang dihitung (untuk tracker runner), null kalau belum ada bulan jalan
     *
     * @return array{depreciation_start_date:?string,last_depreciation_date:?string,accumulated_depreciation:float,months_depreciated:int}
     */
    public function computeDepreciationFields(
        string|\DateTimeInterface $acquisitionDate,
        ?string $explicitStart,
        bool $isDepreciable,
        ?int $usefulLifeMonths,
        float $cost,
        float $salvage,
    ): array {
        if (!$isDepreciable) {
            return [
                'depreciation_start_date' => null,
                'last_depreciation_date' => null,
                'accumulated_depreciation' => 0.0,
                'months_depreciated' => 0,
            ];
        }

        // Tentukan start: pakai input eksplisit kalau ada, kalau tidak default = acquisition_date.
        $start = $explicitStart
            ? Carbon::parse($explicitStart)->startOfDay()
            : Carbon::parse($acquisitionDate)->startOfDay();

        $life = (int) ($usefulLifeMonths ?? 0);
        if ($life <= 0) {
            return [
                'depreciation_start_date' => $start->toDateString(),
                'last_depreciation_date' => null,
                'accumulated_depreciation' => 0.0,
                'months_depreciated' => 0,
            ];
        }

        // months_depreciated = jumlah bulan yang month-end-nya sudah lewat sejak start (bulan berjalan tidak dihitung).
        // Contoh: start 2024-01, today 2026-05-07 → Jan 2024..Apr 2026 = 28 bulan (Mei 2026 belum tutup).
        $today = Carbon::today();
        $months = 0;
        if ($today->gte($start)) {
            $months = ($today->year - $start->year) * 12 + ($today->month - $start->month);
        }
        if ($months < 0) $months = 0;
        if ($months > $life) $months = $life;

        $base = max(0.0, $cost - $salvage);
        $accum = 0.0;
        if ($months > 0 && $base > 0) {
            $monthly = round($base / $life, 2);
            $accum = round($monthly * $months, 2);
            if ($accum > $base) $accum = $base;
        }

        // last_depreciation_date = akhir bulan ke-`months` setelah start (start month + months - 1, endOfMonth).
        // Null kalau belum ada bulan yang berlalu — biar runner mulai dari awal.
        $last = null;
        if ($months > 0) {
            $last = $start->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->toDateString();
        }

        return [
            'depreciation_start_date' => $start->toDateString(),
            'last_depreciation_date' => $last,
            'accumulated_depreciation' => $accum,
            'months_depreciated' => $months,
        ];
    }

    public function post(FixedAsset $asset): FixedAsset
    {
        if (!$asset->canBePosted()) {
            throw new DomainException('Aset belum lengkap atau bukan draft.');
        }

        return DB::transaction(function () use ($asset) {
            $asset->load('category');

            // Safety net: aset purchase-sourced tidak lewat createDraft, jadi depreciation_start_date
            // mungkin masih null. Isi pakai helper yang sama supaya konsisten.
            if ($asset->is_depreciable && !$asset->depreciation_start_date) {
                $computed = $this->computeDepreciationFields(
                    acquisitionDate: $asset->acquisition_date,
                    explicitStart: null,
                    isDepreciable: true,
                    usefulLifeMonths: $asset->useful_life_months,
                    cost: (float) $asset->acquisition_cost,
                    salvage: (float) $asset->salvage_value,
                );
                $asset->depreciation_start_date = $computed['depreciation_start_date'];
                // Jangan timpa accumulated/months untuk aset purchase — itu memang 0 saat fresh acquisition.
            }

            // Buat jurnal saldo awal HANYA untuk source_type='manual'.
            // Untuk source_type='purchase' jurnal sudah dibuat di PurchaseInvoicePostingService.
            if ($asset->source_type === 'manual') {
                $this->createOpeningJournal($asset);
            }

            $asset->status = 'active';
            $asset->posted_at = now();
            $asset->recalculateBookValue();
            $asset->save();

            return $asset;
        });
    }

    protected function createOpeningJournal(FixedAsset $asset): void
    {
        $cat = $asset->category;
        if (!$cat || !$cat->fixed_asset_account_id) {
            throw new DomainException('Kategori aset belum punya akun "Aset Tetap".');
        }

        $cost = (float) $asset->acquisition_cost;
        $accum = (float) $asset->accumulated_depreciation;
        $bookValue = round($cost - $accum, 2);

        $setting = FixedAssetSetting::instance();
        if (!$setting->opening_offset_account_id) {
            throw new DomainException('Akun lawan saldo awal aset belum di-set di pengaturan.');
        }

        $lines = [];

        // Dr Aset Tetap (kategori) — sebesar harga perolehan
        $lines[] = new JournalLineDTO(
            account_id: (int) $cat->fixed_asset_account_id,
            debit: $cost,
            credit: 0,
            description: 'Saldo awal aset: ' . $asset->name,
        );

        // Cr Akumulasi Penyusutan (kategori) — jika sudah disusutkan sebagian
        if ($accum > 0) {
            if (!$cat->accumulated_depreciation_account_id) {
                throw new DomainException('Kategori belum punya akun Akumulasi Penyusutan.');
            }
            $lines[] = new JournalLineDTO(
                account_id: (int) $cat->accumulated_depreciation_account_id,
                debit: 0,
                credit: $accum,
                description: 'Akumulasi penyusutan saldo awal: ' . $asset->name,
            );
        }

        // Cr Akun Lawan (Saldo Laba Ditahan) — sebesar nilai buku
        if ($bookValue > 0) {
            $lines[] = new JournalLineDTO(
                account_id: (int) $setting->opening_offset_account_id,
                debit: 0,
                credit: $bookValue,
                description: 'Lawan saldo awal aset: ' . $asset->name,
            );
        }

        // Jurnal saldo awal pakai tanggal hari ini (tanggal entry ke sistem), bukan acquisition_date.
        // Alasan: acquisition_date bisa jauh di masa lalu (aset existing) dan periode bulan tsb mungkin
        // belum dibuat / sudah closed. acquisition_date tetap jadi atribut aset, bukan tanggal jurnal.
        $dto = new JournalEntryDTO(
            date: now()->toDateString(),
            reference_type: 'fixed_asset_opening',
            reference_id: $asset->id,
            description: 'Saldo awal aset tetap: ' . $asset->asset_code . ' - ' . $asset->name
                . ' (perolehan ' . Carbon::parse($asset->acquisition_date)->format('d/m/Y') . ')',
            lines: $lines,
            is_initial_balance: true,
            reference_number: $asset->asset_code,
        );

        $journal = $this->journalPostingService->post($dto);
        $asset->journal_acquisition_id = $journal->id;
    }

    public function void(FixedAsset $asset): FixedAsset
    {
        if (!$asset->canBeVoided()) {
            throw new DomainException('Aset tidak bisa di-void: sudah ada riwayat penyusutan/transfer/disposisi.');
        }

        return DB::transaction(function () use ($asset) {
            // Void jurnal saldo awal kalau ada (manual posted).
            // Untuk source_type='purchase', journal_acquisition_id null — yang harus di-void adalah journal invoice (lewat void invoice).
            if ($asset->journal_acquisition_id) {
                $j = Journal::find($asset->journal_acquisition_id);
                if ($j && $j->status !== 'void') {
                    $j->status = 'void';
                    $j->voided_at = now();
                    $j->save();
                }
            }

            $asset->status = 'voided';
            $asset->save();

            return $asset;
        });
    }

    public function destroy(FixedAsset $asset): void
    {
        if ($asset->status !== 'draft') {
            throw new DomainException('Hanya aset draft yang bisa dihapus.');
        }
        if ($asset->source_invoice_id) {
            throw new DomainException('Aset dari invoice tidak bisa dihapus. Void invoice-nya untuk membatalkan.');
        }
        $asset->delete();
    }
}
