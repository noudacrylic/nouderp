<?php

namespace App\Modules\SDM\Services;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Modules\Finance\Services\NumberGeneratorTrait;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\KasbonPembayaran;
use App\Modules\SDM\Models\PayrollSetting;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class KasbonPembayaranService
{
    use NumberGeneratorTrait;

    public function __construct(
        protected PeriodService $periodService,
        protected KasbonService $kasbonService,
    ) {}

    public function createDraftManual(array $data): KasbonPembayaran
    {
        return DB::transaction(function () use ($data) {
            $this->validateManual($data);
            $kasbon = Kasbon::findOrFail($data['kasbon_id']);
            if ($kasbon->status !== 'posted') {
                throw new DomainException('Hanya kasbon yang sudah diposting (aktif) yang bisa dibayar.');
            }
            if ((float) $data['jumlah'] > (float) $kasbon->sisa_terhutang + 0.001) {
                throw new DomainException('Jumlah pembayaran melebihi sisa kasbon Rp '
                    . number_format($kasbon->sisa_terhutang, 0, ',', '.'));
            }

            return KasbonPembayaran::create([
                'code'            => $this->generateNumber(KasbonPembayaran::class, 'KSBP', 'code'),
                'kasbon_id'       => $kasbon->id,
                'tanggal_bayar'   => $data['tanggal_bayar'],
                'jumlah'          => $data['jumlah'],
                'source'          => 'manual',
                'cash_account_id' => $data['cash_account_id'],
                'status'          => 'draft',
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);
        });
    }

    public function updateManual(KasbonPembayaran $kp, array $data): KasbonPembayaran
    {
        if (! $kp->canBeEdited()) {
            throw new DomainException('Pembayaran kasbon ini tidak bisa diedit.');
        }
        return DB::transaction(function () use ($kp, $data) {
            $this->validateManual($data);
            $kasbon = Kasbon::findOrFail($data['kasbon_id']);
            if ((float) $data['jumlah'] > (float) $kasbon->sisa_terhutang + 0.001) {
                throw new DomainException('Jumlah pembayaran melebihi sisa kasbon Rp '
                    . number_format($kasbon->sisa_terhutang, 0, ',', '.'));
            }
            $kp->update([
                'kasbon_id'       => $kasbon->id,
                'tanggal_bayar'   => $data['tanggal_bayar'],
                'jumlah'          => $data['jumlah'],
                'cash_account_id' => $data['cash_account_id'],
                'notes'           => $data['notes'] ?? null,
            ]);
            return $kp;
        });
    }

    public function post(KasbonPembayaran $kp): KasbonPembayaran
    {
        if (! $kp->canBePosted()) {
            throw new DomainException('Hanya draft yang bisa diposting.');
        }

        $payDate = Carbon::parse($kp->tanggal_bayar);
        $this->periodService->ensureOpen($payDate);

        $setting = PayrollSetting::singleton();
        if (! $setting->kasbon_receivable_account_id) {
            throw new DomainException('Akun Piutang Kasbon belum di-set di Pengaturan Akun Payroll.');
        }
        if ($kp->source === 'manual' && ! $kp->cash_account_id) {
            throw new DomainException('Pilih akun Kas/Bank penerima.');
        }

        return DB::transaction(function () use ($kp, $setting, $payDate) {
            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (! $period) {
                throw new DomainException('Periode akuntansi tidak ditemukan.');
            }
            $kasbon = $kp->kasbon()->lockForUpdate()->first();

            // Jurnal hanya untuk source=manual. Source=gaji jurnalnya ikut CashDisbursement type=salary.
            if ($kp->source === 'manual') {
                $journal = Journal::create([
                    'journal_number'   => 'KSBP-J-' . $kp->code,
                    'date'             => $kp->tanggal_bayar,
                    'period_id'        => $period->id,
                    'reference_type'   => 'sdm_kasbon_pembayaran',
                    'reference_id'     => $kp->id,
                    'reference_number' => $kp->code,
                    'description'      => 'Pelunasan kasbon ' . $kasbon->code,
                    'status'           => 'posted',
                    'posted_at'        => now(),
                ]);
                JournalLine::create([
                    'journal_id'       => $journal->id,
                    'account_id'       => $kp->cash_account_id,
                    'debit'            => $kp->jumlah,
                    'credit'           => 0,
                    'description'      => 'Terima pelunasan kasbon ' . $kasbon->code,
                    'reference_type'   => 'sdm_kasbon_pembayaran',
                    'reference_id'     => $kp->id,
                    'reference_number' => $kp->code,
                ]);
                JournalLine::create([
                    'journal_id'       => $journal->id,
                    'account_id'       => $setting->kasbon_receivable_account_id,
                    'debit'            => 0,
                    'credit'           => $kp->jumlah,
                    'description'      => 'Pelunasan piutang kasbon ' . $kasbon->code,
                    'reference_type'   => 'sdm_kasbon_pembayaran',
                    'reference_id'     => $kp->id,
                    'reference_number' => $kp->code,
                ]);
                $kp->journal_id = $journal->id;
            }

            $kp->status    = 'posted';
            $kp->posted_at = now();
            $kp->save();

            $this->kasbonService->reduceSisa($kasbon, (float) $kp->jumlah);

            return $kp;
        });
    }

    public function void(KasbonPembayaran $kp): KasbonPembayaran
    {
        if (! $kp->canBeVoided()) {
            throw new DomainException('Hanya pembayaran posted yang bisa di-void.');
        }
        return DB::transaction(function () use ($kp) {
            if ($kp->journal_id) {
                $j = Journal::find($kp->journal_id);
                if ($j) {
                    $j->status = 'void';
                    $j->voided_at = now();
                    $j->save();
                }
            }
            $kasbon = $kp->kasbon()->lockForUpdate()->first();
            $this->kasbonService->revertSisa($kasbon, (float) $kp->jumlah);

            $kp->status    = 'void';
            $kp->voided_at = now();
            $kp->save();
            return $kp;
        });
    }

    /**
     * Dipanggil saat Bayar Gaji di-post (CashDisbursement type=salary):
     * record pemotongan kasbon dari gaji tanpa jurnal mandiri (jurnal sudah inline di CD).
     */
    public function recordFromGaji(int $kasbonId, string $tanggal, float $amount, ?int $slipId = null): KasbonPembayaran
    {
        $kp = KasbonPembayaran::create([
            'code'          => $this->generateNumber(KasbonPembayaran::class, 'KSBG', 'code'),
            'kasbon_id'     => $kasbonId,
            'tanggal_bayar' => $tanggal,
            'jumlah'        => $amount,
            'source'        => 'gaji',
            'slip_gaji_id'  => $slipId,
            'status'        => 'posted',
            'posted_at'     => now(),
            'notes'         => 'Pemotongan dari pembayaran gaji',
            'created_by'    => auth()->id(),
        ]);
        $kasbon = Kasbon::lockForUpdate()->findOrFail($kasbonId);
        $this->kasbonService->reduceSisa($kasbon, $amount);
        return $kp;
    }

    protected function validateManual(array $data): void
    {
        if (empty($data['kasbon_id']))    throw new DomainException('Kasbon wajib dipilih.');
        if (empty($data['tanggal_bayar']))throw new DomainException('Tanggal bayar wajib diisi.');
        if (($data['jumlah'] ?? 0) <= 0)  throw new DomainException('Jumlah harus > 0.');
        if (empty($data['cash_account_id'])) throw new DomainException('Akun Kas/Bank wajib dipilih.');
    }
}
