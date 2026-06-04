<?php

namespace App\Modules\SDM\Services;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Modules\Finance\Services\NumberGeneratorTrait;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\PayrollSetting;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class KasbonService
{
    use NumberGeneratorTrait;

    public function __construct(protected PeriodService $periodService) {}

    public function createDraft(array $data): Kasbon
    {
        return DB::transaction(function () use ($data) {
            $this->validate($data);

            return Kasbon::create([
                'code'                 => $this->generateNumber(Kasbon::class, 'KSB', 'code'),
                'karyawan_id'          => $data['karyawan_id'],
                'tanggal_pengajuan'    => $data['tanggal_pengajuan'],
                'jumlah_pinjaman'      => $data['jumlah_pinjaman'],
                'cicilan_per_bulan'    => $data['cicilan_per_bulan'],
                'tanggal_mulai_potong' => $data['tanggal_mulai_potong'] ?? null,
                'cash_account_id'      => $data['cash_account_id'] ?? null,
                'sisa_terhutang'       => 0,
                'status'               => 'draft',
                'notes'                => $data['notes'] ?? null,
                'created_by'           => auth()->id(),
            ]);
        });
    }

    public function update(Kasbon $kasbon, array $data): Kasbon
    {
        if (! $kasbon->canBeEdited()) {
            throw new DomainException('Kasbon yang sudah diposting tidak bisa diedit.');
        }
        return DB::transaction(function () use ($kasbon, $data) {
            $this->validate($data);
            $kasbon->update([
                'karyawan_id'          => $data['karyawan_id'],
                'tanggal_pengajuan'    => $data['tanggal_pengajuan'],
                'jumlah_pinjaman'      => $data['jumlah_pinjaman'],
                'cicilan_per_bulan'    => $data['cicilan_per_bulan'],
                'tanggal_mulai_potong' => $data['tanggal_mulai_potong'] ?? null,
                'cash_account_id'      => $data['cash_account_id'] ?? null,
                'notes'                => $data['notes'] ?? null,
            ]);
            return $kasbon;
        });
    }

    public function post(Kasbon $kasbon): Kasbon
    {
        if (! $kasbon->canBePosted()) {
            throw new DomainException('Hanya kasbon draft yang bisa diposting.');
        }
        if (! $kasbon->cash_account_id) {
            throw new DomainException('Pilih akun Kas/Bank pencairan dulu sebelum posting.');
        }

        $setting = PayrollSetting::singleton();
        if (! $setting->kasbon_receivable_account_id) {
            throw new DomainException('Akun Piutang Kasbon belum di-set di Pengaturan Akun Payroll.');
        }

        $payDate = Carbon::parse($kasbon->tanggal_pengajuan);
        $this->periodService->ensureOpen($payDate);

        return DB::transaction(function () use ($kasbon, $setting, $payDate) {
            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (! $period) {
                throw new DomainException('Periode akuntansi tidak ditemukan.');
            }

            $karyawan = $kasbon->karyawan()->first();
            $desc = 'Kasbon ' . $kasbon->code . ' - ' . ($karyawan->name ?? '');

            $journal = Journal::create([
                'journal_number'   => 'KSB-J-' . $kasbon->code,
                'date'             => $kasbon->tanggal_pengajuan,
                'period_id'        => $period->id,
                'reference_type'   => 'sdm_kasbon',
                'reference_id'     => $kasbon->id,
                'reference_number' => $kasbon->code,
                'description'      => $desc,
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            JournalLine::create([
                'journal_id'       => $journal->id,
                'account_id'       => $setting->kasbon_receivable_account_id,
                'debit'            => $kasbon->jumlah_pinjaman,
                'credit'           => 0,
                'description'      => 'Piutang kasbon ' . ($karyawan->name ?? ''),
                'reference_type'   => 'sdm_kasbon',
                'reference_id'     => $kasbon->id,
                'reference_number' => $kasbon->code,
            ]);
            JournalLine::create([
                'journal_id'       => $journal->id,
                'account_id'       => $kasbon->cash_account_id,
                'debit'            => 0,
                'credit'           => $kasbon->jumlah_pinjaman,
                'description'      => 'Pencairan kasbon ' . $kasbon->code,
                'reference_type'   => 'sdm_kasbon',
                'reference_id'     => $kasbon->id,
                'reference_number' => $kasbon->code,
            ]);

            $kasbon->journal_id     = $journal->id;
            $kasbon->status         = 'posted';
            $kasbon->sisa_terhutang = $kasbon->jumlah_pinjaman;
            $kasbon->posted_at      = now();
            $kasbon->save();

            return $kasbon;
        });
    }

    public function void(Kasbon $kasbon, ?string $reason = null): Kasbon
    {
        if (! $kasbon->canBeVoided()) {
            throw new DomainException('Kasbon ini tidak bisa di-void.');
        }

        $pembayaranAktif = $kasbon->pembayaran()->where('status', 'posted')->exists();
        if ($pembayaranAktif) {
            throw new DomainException('Kasbon punya pembayaran/pemotongan aktif. Void pembayarannya dulu.');
        }

        return DB::transaction(function () use ($kasbon, $reason) {
            if ($kasbon->journal_id) {
                $j = Journal::find($kasbon->journal_id);
                if ($j) {
                    $j->status = 'void';
                    $j->voided_at = now();
                    $j->save();
                }
            }
            $kasbon->status         = 'void';
            $kasbon->sisa_terhutang = 0;
            $kasbon->voided_at      = now();
            $kasbon->void_reason    = $reason;
            $kasbon->save();
            return $kasbon;
        });
    }

    /**
     * Kurangi sisa_terhutang. Dipanggil saat KasbonPembayaran posted.
     */
    public function reduceSisa(Kasbon $kasbon, float $amount): void
    {
        $kasbon->refresh();
        $sisa = max(0, (float) $kasbon->sisa_terhutang - $amount);
        $kasbon->sisa_terhutang = round($sisa, 2);
        if ($sisa <= 0.001 && $kasbon->status === 'posted') {
            $kasbon->status = 'lunas';
        }
        $kasbon->save();
    }

    /**
     * Balikkan sisa_terhutang. Dipanggil saat KasbonPembayaran di-void.
     */
    public function revertSisa(Kasbon $kasbon, float $amount): void
    {
        $kasbon->refresh();
        $sisa = (float) $kasbon->sisa_terhutang + $amount;
        $kasbon->sisa_terhutang = round($sisa, 2);
        if ($kasbon->status === 'lunas' && $sisa > 0.001) {
            $kasbon->status = 'posted';
        }
        $kasbon->save();
    }

    protected function validate(array $data): void
    {
        if (empty($data['karyawan_id']))       throw new DomainException('Karyawan wajib dipilih.');
        if (empty($data['tanggal_pengajuan'])) throw new DomainException('Tanggal pengajuan wajib diisi.');
        if (($data['jumlah_pinjaman'] ?? 0) <= 0) throw new DomainException('Jumlah pinjaman harus > 0.');
        if (($data['cicilan_per_bulan'] ?? 0) <= 0) throw new DomainException('Cicilan per bulan harus > 0.');
        if ((float) $data['cicilan_per_bulan'] > (float) $data['jumlah_pinjaman']) {
            throw new DomainException('Cicilan per bulan tidak boleh lebih besar dari jumlah pinjaman.');
        }
    }
}
