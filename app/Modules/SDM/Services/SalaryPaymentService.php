<?php

namespace App\Modules\SDM\Services;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Modules\Finance\Services\NumberGeneratorTrait;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\PayrollSetting;
use App\Modules\SDM\Models\SalaryPayment;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalaryPaymentService
{
    use NumberGeneratorTrait;

    public function __construct(
        protected PeriodService $periodService,
        protected KasbonPembayaranService $kasbonPembayaran,
    ) {}

    public function createDraft(array $data): SalaryPayment
    {
        return DB::transaction(function () use ($data) {
            $this->validate($data);
            $this->preventDuplicate($data, null);

            return SalaryPayment::create([
                'number'               => $this->generateNumber(SalaryPayment::class, 'SP'),
                'date'                 => $data['date'],
                'karyawan_id'          => $data['karyawan_id'],
                'periode_bulan'        => $data['periode_bulan'],
                'periode_tahun'        => $data['periode_tahun'],
                'cash_account_id'      => $data['cash_account_id'],
                'bruto_gaji'           => $data['bruto_gaji'] ?? 0,
                'bpjs_kes_employee'    => $data['bpjs_kes_employee'] ?? 0,
                'bpjs_kes_company'     => $data['bpjs_kes_company'] ?? 0,
                'bpjs_tk_employee'     => $data['bpjs_tk_employee'] ?? 0,
                'bpjs_tk_company'      => $data['bpjs_tk_company'] ?? 0,
                'pph21_amount'         => $data['pph21_amount'] ?? 0,
                'kasbon_potongan'      => $data['kasbon_potongan'] ?? 0,
                'nett_dibayar'         => $data['nett_dibayar'] ?? 0,
                'admin_fee'            => $data['admin_fee'] ?? 0,
                'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
                'status'               => 'draft',
                'notes'                => $data['notes'] ?? null,
                'created_by'           => auth()->id(),
            ]);
        });
    }

    public function update(SalaryPayment $sp, array $data): SalaryPayment
    {
        if (! $sp->canBeEdited()) {
            throw new DomainException('Hanya draft yang bisa diedit.');
        }
        return DB::transaction(function () use ($sp, $data) {
            $this->validate($data);
            $this->preventDuplicate($data, $sp->id);

            $sp->update([
                'date'                 => $data['date'],
                'karyawan_id'          => $data['karyawan_id'],
                'periode_bulan'        => $data['periode_bulan'],
                'periode_tahun'        => $data['periode_tahun'],
                'cash_account_id'      => $data['cash_account_id'],
                'bruto_gaji'           => $data['bruto_gaji'] ?? 0,
                'bpjs_kes_employee'    => $data['bpjs_kes_employee'] ?? 0,
                'bpjs_kes_company'     => $data['bpjs_kes_company'] ?? 0,
                'bpjs_tk_employee'     => $data['bpjs_tk_employee'] ?? 0,
                'bpjs_tk_company'      => $data['bpjs_tk_company'] ?? 0,
                'pph21_amount'         => $data['pph21_amount'] ?? 0,
                'kasbon_potongan'      => $data['kasbon_potongan'] ?? 0,
                'nett_dibayar'         => $data['nett_dibayar'] ?? 0,
                'admin_fee'            => $data['admin_fee'] ?? 0,
                'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
                'notes'                => $data['notes'] ?? null,
            ]);
            return $sp;
        });
    }

    public function post(SalaryPayment $sp): SalaryPayment
    {
        if ($sp->isPosted()) throw new DomainException('Sudah diposting.');
        if ($sp->isVoid())   throw new DomainException('Sudah di-void.');

        $payDate = Carbon::parse($sp->date);
        $this->periodService->ensureOpen($payDate);

        $setting = PayrollSetting::singleton();
        if (! $setting->expense_salary_account_id) {
            throw new DomainException('Akun Beban Gaji belum di-set di Pengaturan Akun & Rate Payroll.');
        }
        if (($sp->bpjs_kes_employee + $sp->bpjs_kes_company) > 0 && ! $setting->bpjs_kesehatan_liability_account_id) {
            throw new DomainException('Akun Hutang BPJS Kesehatan belum di-set.');
        }
        if (($sp->bpjs_tk_employee + $sp->bpjs_tk_company) > 0 && ! $setting->bpjs_tk_liability_account_id) {
            throw new DomainException('Akun Hutang BPJS Ketenagakerjaan belum di-set.');
        }
        if ($sp->pph21_amount > 0 && ! $setting->pph21_liability_account_id) {
            throw new DomainException('Akun Hutang PPh 21 belum di-set.');
        }
        if ($sp->kasbon_potongan > 0 && ! $setting->kasbon_receivable_account_id) {
            throw new DomainException('Akun Piutang Kasbon belum di-set.');
        }
        $bpjsCompanyTotal = (float) $sp->bpjs_kes_company + (float) $sp->bpjs_tk_company;
        if ($bpjsCompanyTotal > 0 && ! $setting->bpjs_company_expense_account_id) {
            throw new DomainException('Akun Beban BPJS Perusahaan belum di-set.');
        }
        if ((float) $sp->admin_fee > 0 && ! $sp->admin_fee_account_id) {
            throw new DomainException('Akun Beban Admin Bank wajib dipilih saat ada biaya admin.');
        }

        return DB::transaction(function () use ($sp, $setting, $payDate) {
            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (! $period) throw new DomainException('Periode akuntansi tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => 'SP-J-' . $sp->number,
                'date'             => $sp->date,
                'period_id'        => $period->id,
                'reference_type'   => 'sdm_salary_payment',
                'reference_id'     => $sp->id,
                'reference_number' => $sp->number,
                'description'      => 'Pembayaran gaji ' . $sp->karyawan->name . ' periode ' . $sp->periodeLabel(),
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            $karyawanLabel = $sp->karyawan->name;
            $periodeLabel  = $sp->periodeLabel();

            $line = function (int $accountId, float $debit, float $credit, string $desc) use ($journal, $sp) {
                if ($debit == 0 && $credit == 0) return;
                JournalLine::create([
                    'journal_id'       => $journal->id,
                    'account_id'       => $accountId,
                    'debit'            => $debit,
                    'credit'           => $credit,
                    'description'      => $desc,
                    'reference_type'   => 'sdm_salary_payment',
                    'reference_id'     => $sp->id,
                    'reference_number' => $sp->number,
                ]);
            };

            // Dr Beban Gaji = bruto_gaji
            $line($setting->expense_salary_account_id, (float) $sp->bruto_gaji, 0,
                'Beban gaji ' . $karyawanLabel . ' (' . $periodeLabel . ')');

            // Dr Beban BPJS Perusahaan = bpjs_kes_company + bpjs_tk_company
            if (($sp->bpjs_kes_company + $sp->bpjs_tk_company) > 0) {
                $line($setting->bpjs_company_expense_account_id,
                    (float) ($sp->bpjs_kes_company + $sp->bpjs_tk_company), 0,
                    'Iuran BPJS perusahaan ' . $karyawanLabel);
            }

            // Cr Hutang BPJS Kesehatan = emp + company
            $bpjsKesTotal = (float) $sp->bpjs_kes_employee + (float) $sp->bpjs_kes_company;
            if ($bpjsKesTotal > 0) {
                $line($setting->bpjs_kesehatan_liability_account_id, 0, $bpjsKesTotal,
                    'Hutang BPJS Kesehatan ' . $karyawanLabel);
            }

            // Cr Hutang BPJS TK = emp + company
            $bpjsTkTotal = (float) $sp->bpjs_tk_employee + (float) $sp->bpjs_tk_company;
            if ($bpjsTkTotal > 0) {
                $line($setting->bpjs_tk_liability_account_id, 0, $bpjsTkTotal,
                    'Hutang BPJS Ketenagakerjaan ' . $karyawanLabel);
            }

            // Cr Hutang PPh 21
            if ($sp->pph21_amount > 0) {
                $line($setting->pph21_liability_account_id, 0, (float) $sp->pph21_amount,
                    'Hutang PPh 21 ' . $karyawanLabel);
            }

            // Cr Piutang Kasbon
            if ($sp->kasbon_potongan > 0) {
                $line($setting->kasbon_receivable_account_id, 0, (float) $sp->kasbon_potongan,
                    'Pelunasan kasbon dari gaji ' . $karyawanLabel);

                // Tambah record KasbonPembayaran source=gaji untuk reduce sisa kasbon aktif (FIFO)
                $this->absorbKasbon($sp);
            }

            // Dr Beban Admin Bank (kalau transfer beda bank)
            if ((float) $sp->admin_fee > 0) {
                $line($sp->admin_fee_account_id, (float) $sp->admin_fee, 0,
                    'Biaya admin transfer gaji ' . $karyawanLabel);
            }

            // Cr Kas/Bank = nett_dibayar + admin_fee (total kas keluar)
            $totalCashOut = (float) $sp->nett_dibayar + (float) $sp->admin_fee;
            $line($sp->cash_account_id, 0, $totalCashOut,
                'Bayar gaji ' . $karyawanLabel . ' (' . $periodeLabel . ')');

            $this->validateBalance($journal->id);

            $sp->journal_id = $journal->id;
            $sp->status     = 'posted';
            $sp->posted_at  = now();
            $sp->save();

            return $sp;
        });
    }

    public function void(SalaryPayment $sp, ?string $reason = null): SalaryPayment
    {
        if (! $sp->canBeVoided()) {
            throw new DomainException('Hanya posted yang bisa di-void.');
        }
        return DB::transaction(function () use ($sp, $reason) {
            if ($sp->journal_id) {
                $j = Journal::find($sp->journal_id);
                if ($j) {
                    $j->status    = 'void';
                    $j->voided_at = now();
                    $j->save();
                }
            }
            // Revert kasbon yang dipotong
            \App\Modules\SDM\Models\KasbonPembayaran::where('source', 'gaji')
                ->whereNotNull('slip_gaji_id') // dipakai sebagai marker SalaryPayment ID
                ->where('slip_gaji_id', $sp->id)
                ->get()
                ->each(function ($kp) {
                    $kasbon = Kasbon::lockForUpdate()->find($kp->kasbon_id);
                    if ($kasbon) {
                        $kasbon->sisa_terhutang = (float) $kasbon->sisa_terhutang + (float) $kp->jumlah;
                        $kasbon->save();
                    }
                    $kp->status    = 'void';
                    $kp->voided_at = now();
                    $kp->save();
                });

            $sp->status      = 'void';
            $sp->voided_at   = now();
            $sp->void_reason = $reason;
            $sp->save();
            return $sp;
        });
    }

    protected function absorbKasbon(SalaryPayment $sp): void
    {
        $sisa = (float) $sp->kasbon_potongan;
        if ($sisa <= 0) return;

        $kasbons = Kasbon::where('karyawan_id', $sp->karyawan_id)
            ->where('status', 'posted')
            ->where('sisa_terhutang', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($kasbons as $kasbon) {
            if ($sisa <= 0) break;
            $amount = min((float) $kasbon->sisa_terhutang, $sisa);
            $this->kasbonPembayaran->recordFromGaji($kasbon->id, $sp->date->toDateString(), $amount, $sp->id);
            $sisa -= $amount;
        }
    }

    protected function validate(array $data): void
    {
        if (empty($data['date'])) throw new DomainException('Tanggal wajib.');
        if (empty($data['karyawan_id'])) throw new DomainException('Karyawan wajib dipilih.');
        if (empty($data['periode_bulan']) || empty($data['periode_tahun'])) {
            throw new DomainException('Periode (bulan/tahun) wajib.');
        }
        if (empty($data['cash_account_id'])) throw new DomainException('Akun Kas/Bank wajib dipilih.');
        if ((float) ($data['nett_dibayar'] ?? 0) <= 0) {
            throw new DomainException('Nett dibayar harus > 0.');
        }
        // Validasi balance: bruto + bpjs_company - (bpjs_emp + pph21 + kasbon) == nett
        $bruto  = (float) ($data['bruto_gaji'] ?? 0);
        $emp    = (float) ($data['bpjs_kes_employee'] ?? 0)
                + (float) ($data['bpjs_tk_employee'] ?? 0)
                + (float) ($data['pph21_amount'] ?? 0)
                + (float) ($data['kasbon_potongan'] ?? 0);
        $nett   = (float) ($data['nett_dibayar'] ?? 0);
        if (round($bruto - $emp - $nett, 2) !== 0.00) {
            throw new DomainException(sprintf(
                'Tidak balance: bruto (%s) - potongan karyawan (%s) ≠ nett (%s).',
                number_format($bruto, 0, ',', '.'),
                number_format($emp, 0, ',', '.'),
                number_format($nett, 0, ',', '.'),
            ));
        }
    }

    protected function preventDuplicate(array $data, ?int $excludeId): void
    {
        $exists = SalaryPayment::where('karyawan_id', $data['karyawan_id'])
            ->where('periode_bulan', $data['periode_bulan'])
            ->where('periode_tahun', $data['periode_tahun'])
            ->whereIn('status', ['draft', 'posted'])
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
        if ($exists) {
            $karyawan = Karyawan::find($data['karyawan_id']);
            $bulanNama = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
            throw new DomainException('Sudah ada pembayaran gaji aktif untuk ' . ($karyawan->name ?? '?')
                . ' periode ' . ($bulanNama[$data['periode_bulan']] ?? '?') . ' ' . $data['periode_tahun']
                . '. Void dulu yang lama kalau mau buat baru.');
        }
    }

    protected function validateBalance(int $journalId): void
    {
        $lines = JournalLine::where('journal_id', $journalId)->get();
        $debit  = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('credit'), 2);
        if ($debit !== $credit) {
            throw new DomainException("Journal not balanced: Dr={$debit} Cr={$credit}");
        }
    }
}
