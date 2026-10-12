<?php

namespace App\Modules\Finance\Services;

use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Models\TaxAccrual;
use App\Models\TaxPayment;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hutang PPh Final UMKM (akun 2114) — pajak setor sendiri yang belum disetor.
 *
 * Per masa: hutang yang semestinya = harus setor − setoran lama yang langsung mendebit 5012
 * (setoran itu sudah jadi beban, tak perlu lewat hutang). Selisihnya terhadap yang sudah
 * dibukukan dijurnal Dr 5012 / Cr 2114 (atau sebaliknya bila turun, mis. ada retur susulan).
 * Setoran baru mendebit 2114, jadi saldo 2114 = pajak yang belum disetor.
 *
 * Dipanggil dari tombol "Bukukan hutang" di rincian masa, otomatis saat Catat Setoran, dan
 * perintah terjadwal `pajak:bukukan-hutang` tiap tanggal 1 untuk masa bulan lalu.
 */
class PphFinalHutangService
{
    public const AKUN_HUTANG = '2114';
    public const REF = 'pph_final_accrual';

    public function __construct(
        private PphFinalReportService $report,
        private JournalPostingService $poster,
    ) {}

    /**
     * @param float|null $harusSetor isi bila sudah dihitung (mis. dari rekap setahun)
     * @return array{target: float, dibukukan: float, selisih: float, langsung: float}
     */
    public function status(Carbon $masa, ?float $harusSetor = null): array
    {
        $masa = $masa->copy()->startOfMonth();
        $harus = $harusSetor ?? $this->report->rincianMasa($masa->year, $masa->month)['ringkas']['harus_setor'];
        $langsung = (float) TaxPayment::pphFinal()->whereDate('period', $masa)->where('via_hutang', false)->sum('amount');
        $target = max(0.0, round($harus - $langsung, 0));
        $dibukukan = (float) TaxAccrual::pphFinal()->whereDate('period', $masa)->value('amount');

        return [
            'target'    => $target,
            'dibukukan' => $dibukukan,
            'selisih'   => round($target - $dibukukan, 0),
            'langsung'  => $langsung,
        ];
    }

    /** Sudah dibukukan per bulan masa (1..12) setahun. */
    public function dibukukanPerMasa(int $tahun): array
    {
        return TaxAccrual::pphFinal()->whereYear('period', $tahun)->get(['period', 'amount'])
            ->mapWithKeys(fn ($a) => [(int) $a->period->format('n') => (float) $a->amount])->all();
    }

    /** Setoran yang langsung mendebit 5012 per bulan masa (1..12). */
    public function langsungPerMasa(int $tahun): array
    {
        return TaxPayment::pphFinal()->whereYear('period', $tahun)->where('via_hutang', false)
            ->get(['period', 'amount'])->groupBy(fn ($p) => (int) $p->period->format('n'))
            ->map(fn ($g) => (float) $g->sum('amount'))->all();
    }

    /**
     * Jurnal selisih hutang masa ini. Tanggal jurnal = akhir masa (hari ini bila masa masih
     * berjalan atau periode akhir masa sudah ditutup). Mengembalikan nominal yang dijurnal
     * (+ menambah hutang, − mengurangi, 0 = sudah sesuai).
     */
    public function bukukan(Carbon $masa, ?float $harusSetor = null): float
    {
        $masa = $masa->copy()->startOfMonth();
        $st = $this->status($masa, $harusSetor);
        if (abs($st['selisih']) < 1) {
            return 0.0;
        }

        $akunHutang = DB::table('accounts')->where('code', self::AKUN_HUTANG)->value('id');
        $akunBeban  = DB::table('accounts')->where('code', PphFinalReportService::AKUN_PPH_FINAL)->value('id');
        if (!$akunHutang || !$akunBeban) {
            throw new DomainException('Akun 2114 Hutang PPh Final UMKM / 5012 PPh Final belum ada di Chart of Accounts.');
        }

        $nilai = abs($st['selisih']);
        $naik = $st['selisih'] > 0;
        $label = 'PPh Final UMKM 0,5% masa ' . $masa->translatedFormat('F Y');

        return DB::transaction(function () use ($masa, $st, $nilai, $naik, $label, $akunHutang, $akunBeban) {
            $row = TaxAccrual::firstOrCreate(
                ['tax_type' => TaxPayment::PPH_FINAL_UMKM, 'period' => $masa->toDateString()],
                ['amount' => 0]
            );

            $tanggal = $masa->copy()->endOfMonth();
            if ($tanggal->isFuture() || $this->periodeTutup($tanggal)) {
                $tanggal = now();
            }

            $this->poster->post(new JournalEntryDTO(
                date: $tanggal->toDateString(),
                reference_type: self::REF,
                reference_id: $row->id,
                description: ($naik ? 'Hutang ' : 'Koreksi turun hutang ') . $label,
                reference_number: 'PPH-' . $masa->format('Y-m'),
                allow_repeat: true,
                lines: [
                    new JournalLineDTO(account_id: $akunBeban, debit: $naik ? $nilai : 0, credit: $naik ? 0 : $nilai,
                        description: $label),
                    new JournalLineDTO(account_id: $akunHutang, debit: $naik ? 0 : $nilai, credit: $naik ? $nilai : 0,
                        description: 'Hutang ' . $label),
                ],
            ));

            $row->update(['amount' => $st['target']]);

            return $naik ? $nilai : -$nilai;
        });
    }

    /** Jurnal hutang satu masa, terbaru dulu. */
    public function jurnal(Carbon $masa)
    {
        $id = TaxAccrual::pphFinal()->whereDate('period', $masa->copy()->startOfMonth())->value('id');
        if (!$id) {
            return collect();
        }

        return DB::table('journals')->where('reference_type', self::REF)->where('reference_id', $id)
            ->where('status', 'posted')->orderByDesc('id')->get(['id', 'journal_number', 'date', 'description']);
    }

    /** Saldo akun 2114 (kredit − debit) = pajak yang belum disetor. */
    public function saldoHutang(): float
    {
        $akun = DB::table('accounts')->where('code', self::AKUN_HUTANG)->value('id');
        if (!$akun) {
            return 0.0;
        }

        return (float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->where('l.account_id', $akun)
            ->selectRaw('COALESCE(SUM(l.credit - l.debit), 0) as s')->value('s');
    }

    private function periodeTutup(Carbon $tanggal): bool
    {
        return DB::table('accounting_periods')->where('year', $tanggal->year)->where('month', $tanggal->month)
            ->where('status', 'closed')->exists();
    }
}
