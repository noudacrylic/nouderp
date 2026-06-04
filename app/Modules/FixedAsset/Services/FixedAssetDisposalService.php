<?php

namespace App\Modules\FixedAsset\Services;

use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Models\FixedAssetDisposal;
use App\Modules\FixedAsset\Models\FixedAssetSetting;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\Core\Period\PeriodService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class FixedAssetDisposalService
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
        protected PeriodService $periodService
    ) {}

    public function generateNumber(): string
    {
        $prefix = 'FAD/' . now()->format('Y/m') . '/';
        $latest = FixedAssetDisposal::where('disposal_number', 'LIKE', $prefix . '%')
            ->orderByDesc('disposal_number')
            ->value('disposal_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function createDraft(array $data): FixedAssetDisposal
    {
        return DB::transaction(function () use ($data) {
            $asset = FixedAsset::findOrFail($data['fixed_asset_id']);
            if ($asset->status !== 'active') {
                throw new DomainException('Hanya aset aktif yang bisa di-disposisi.');
            }

            $type = $data['disposal_type'] ?? 'sale';
            $proceeds = (float) ($data['proceeds_amount'] ?? 0);

            if ($type === 'sale' && $proceeds <= 0) {
                throw new DomainException('Disposisi tipe "Jual" wajib punya nilai jual > 0.');
            }

            if ($proceeds > 0 && empty($data['proceeds_account_id'])) {
                $setting = FixedAssetSetting::instance();
                $data['proceeds_account_id'] = $setting->default_disposal_proceeds_account_id;
            }

            $bookValue = round((float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation, 2);
            $gainLoss = round($proceeds - $bookValue, 2);

            return FixedAssetDisposal::create([
                'disposal_number' => $data['disposal_number'] ?? $this->generateNumber(),
                'fixed_asset_id' => $asset->id,
                'disposal_date' => $data['disposal_date'],
                'disposal_type' => $type,
                'proceeds_amount' => $proceeds,
                'proceeds_account_id' => $data['proceeds_account_id'] ?? null,
                'book_value_at_disposal' => $bookValue,
                'gain_loss_amount' => $gainLoss,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $data['created_by'] ?? null,
            ]);
        });
    }

    public function update(FixedAssetDisposal $d, array $data): FixedAssetDisposal
    {
        if ($d->status !== 'draft') {
            throw new DomainException('Hanya disposisi draft yang bisa diedit.');
        }

        $asset = $d->asset;
        $type = $data['disposal_type'] ?? $d->disposal_type;
        $proceeds = (float) ($data['proceeds_amount'] ?? $d->proceeds_amount);

        if ($type === 'sale' && $proceeds <= 0) {
            throw new DomainException('Disposisi tipe "Jual" wajib punya nilai jual > 0.');
        }

        $bookValue = round((float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation, 2);
        $gainLoss = round($proceeds - $bookValue, 2);

        $d->fill([
            'disposal_date' => $data['disposal_date'] ?? $d->disposal_date,
            'disposal_type' => $type,
            'proceeds_amount' => $proceeds,
            'proceeds_account_id' => $data['proceeds_account_id'] ?? $d->proceeds_account_id,
            'book_value_at_disposal' => $bookValue,
            'gain_loss_amount' => $gainLoss,
            'notes' => $data['notes'] ?? $d->notes,
        ]);
        $d->save();
        return $d;
    }

    public function post(FixedAssetDisposal $d): FixedAssetDisposal
    {
        if ($d->status !== 'draft') {
            throw new DomainException('Hanya disposisi draft yang bisa di-post.');
        }

        $asset = $d->asset;
        if (!$asset || $asset->status !== 'active') {
            throw new DomainException('Aset tidak aktif.');
        }

        $disposalDate = Carbon::parse($d->disposal_date);
        $this->periodService->ensureOpen($disposalDate);

        return DB::transaction(function () use ($d, $asset, $disposalDate) {
            $cat = $asset->category;
            if (!$cat) {
                throw new DomainException('Aset tidak punya kategori.');
            }
            if (!$cat->fixed_asset_account_id) {
                throw new DomainException('Kategori belum punya akun aset.');
            }

            // Akun gain & loss diambil dari Settings global (FixedAssetSetting), bukan per kategori.
            // Sistem otomatis pilih: hasil positif → akun keuntungan; negatif → akun kerugian.
            $setting = FixedAssetSetting::instance();
            if (!$setting->disposal_gain_account_id || !$setting->disposal_loss_account_id) {
                throw new DomainException('Akun keuntungan/kerugian disposisi belum di-set di Settings Aset Tetap.');
            }

            $cost = (float) $asset->acquisition_cost;
            $accum = (float) $asset->accumulated_depreciation;
            $proceeds = (float) $d->proceeds_amount;
            $gainLoss = round($proceeds - ($cost - $accum), 2);

            $lines = [];

            // Dr Proceeds (Kas/Bank/AR) — jika ada nilai jual
            if ($proceeds > 0) {
                if (!$d->proceeds_account_id) {
                    throw new DomainException('Akun penerimaan wajib diisi untuk disposisi dengan nilai jual.');
                }
                $lines[] = new JournalLineDTO(
                    account_id: (int) $d->proceeds_account_id,
                    debit: $proceeds,
                    credit: 0,
                    description: 'Penerimaan disposisi: ' . $asset->asset_code,
                );
            }

            // Dr Akumulasi Penyusutan — kembalikan akumulasi yang sudah terbentuk
            if ($accum > 0) {
                if (!$cat->accumulated_depreciation_account_id) {
                    throw new DomainException('Kategori belum punya akun akumulasi penyusutan.');
                }
                $lines[] = new JournalLineDTO(
                    account_id: (int) $cat->accumulated_depreciation_account_id,
                    debit: $accum,
                    credit: 0,
                    description: 'Reverse akumulasi penyusutan: ' . $asset->asset_code,
                );
            }

            // Dr Kerugian (jika loss) — pakai akun loss dari setting
            if ($gainLoss < 0) {
                $lines[] = new JournalLineDTO(
                    account_id: (int) $setting->disposal_loss_account_id,
                    debit: abs($gainLoss),
                    credit: 0,
                    description: 'Kerugian pelepasan aset: ' . $asset->asset_code,
                );
            }

            // Cr Aset Tetap — sebesar harga perolehan
            $lines[] = new JournalLineDTO(
                account_id: (int) $cat->fixed_asset_account_id,
                debit: 0,
                credit: $cost,
                description: 'Pelepasan aset: ' . $asset->asset_code,
            );

            // Cr Keuntungan (jika gain) — pakai akun gain dari setting
            if ($gainLoss > 0) {
                $lines[] = new JournalLineDTO(
                    account_id: (int) $setting->disposal_gain_account_id,
                    debit: 0,
                    credit: $gainLoss,
                    description: 'Keuntungan pelepasan aset: ' . $asset->asset_code,
                );
            }

            $dto = new JournalEntryDTO(
                date: $disposalDate->toDateString(),
                reference_type: 'fixed_asset_disposal',
                reference_id: $d->id,
                description: 'Disposisi aset tetap ' . $asset->asset_code . ' - ' . $asset->name,
                lines: $lines,
                is_initial_balance: false,
                reference_number: $d->disposal_number,
            );

            $journal = $this->journalPostingService->post($dto);

            $d->status = 'posted';
            $d->posted_at = now();
            $d->journal_id = $journal->id;
            $d->save();

            $asset->status = 'disposed';
            $asset->disposed_date = $disposalDate->toDateString();
            $asset->journal_disposal_id = $journal->id;
            $asset->save();

            return $d;
        });
    }

    public function void(FixedAssetDisposal $d): FixedAssetDisposal
    {
        if (!$d->canBeVoided()) {
            throw new DomainException('Disposisi tidak bisa di-void.');
        }

        return DB::transaction(function () use ($d) {
            if ($d->journal_id) {
                $j = Journal::find($d->journal_id);
                if ($j && $j->status !== 'void') {
                    $j->status = 'void';
                    $j->voided_at = now();
                    $j->save();
                }
            }

            $asset = $d->asset;
            if ($asset && $asset->status === 'disposed') {
                $asset->status = 'active';
                $asset->disposed_date = null;
                $asset->journal_disposal_id = null;
                $asset->save();
            }

            $d->status = 'void';
            $d->voided_at = now();
            $d->save();

            return $d;
        });
    }

    public function destroy(FixedAssetDisposal $d): void
    {
        if ($d->status !== 'draft') {
            throw new DomainException('Hanya disposisi draft yang bisa dihapus.');
        }
        $d->delete();
    }
}
