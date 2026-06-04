<?php

namespace App\Modules\Finance\Services;

use App\Models\FreightSetting;
use App\Modules\Finance\Models\CashDisbursement;
use Carbon\Carbon;
use DomainException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Pencatatan biaya API Biteship (cek ongkir & cek resi) ke Pengeluaran Umum.
 * Sumber angka: export "Transaksi API" dari dashboard Biteship (Biteship tidak punya API billing publik).
 *
 * Jurnal per tanggal: Dr Beban API Biteship (5291) / Cr Saldo Biteship (1115).
 * Idempotent per-tanggal via CashDisbursement.reference = 'BITESHIP-API:{Y-m-d}'.
 */
class BiteshipApiCostService
{
    public function __construct(private CashDisbursementService $cdSvc) {}

    /** Prefix reference idempotency per-tanggal. */
    public const REF_PREFIX = 'BITESHIP-API:';

    /**
     * Catat biaya API untuk SATU tanggal (idempotent).
     * @return array{status:'created'|'skipped'|'error', date:string, amount:float, number:?string, reason:?string}
     */
    public function recordDay(string $date, float $amount): array
    {
        $date   = Carbon::parse($date)->toDateString();
        $amount = round($amount, 2);
        $refKey = self::REF_PREFIX . $date;

        if ($amount <= 0) {
            return ['status' => 'skipped', 'date' => $date, 'amount' => $amount, 'number' => null, 'reason' => 'nominal 0'];
        }

        $existing = CashDisbursement::where('reference', $refKey)->where('status', '!=', 'void')->first();
        if ($existing) {
            return ['status' => 'skipped', 'date' => $date, 'amount' => $amount, 'number' => $existing->number, 'reason' => 'sudah dicatat'];
        }

        [$saldoId, $feeId] = $this->accountIds();

        try {
            $cd = $this->cdSvc->createDraft([
                'type'            => 'general',
                'date'            => $date,
                'cash_account_id' => $saldoId,   // Cr Saldo Biteship (1115)
                'payee'           => 'Biteship',
                'reference'       => $refKey,
                'notes'           => 'Biaya API Biteship ' . $date,
                'lines'           => [[
                    'account_id'  => $feeId,     // Dr Beban API Biteship (5291)
                    'amount'      => $amount,
                    'description' => 'Biaya request API Biteship ' . $date,
                ]],
            ]);
            $this->cdSvc->post($cd);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'date' => $date, 'amount' => $amount, 'number' => null, 'reason' => $e->getMessage()];
        }

        return ['status' => 'created', 'date' => $date, 'amount' => $amount, 'number' => $cd->number, 'reason' => null];
    }

    /**
     * Parse rows hasil `toArray()` dari export Biteship "Transaksi API" lalu catat per tanggal.
     * Format export: header di baris 0 dengan kolom `date` & `total_amount` (1 baris = 1 tanggal).
     * Tahan-banting: deteksi kolom dari nama header; agregasi per-tanggal kalau ada baris ganda.
     *
     * @return array{created:array, skipped:array, errors:array, total_created:float, count_created:int}
     */
    public function importRows(array $rows): array
    {
        if (count($rows) < 2) {
            throw new DomainException('File kosong / tidak ada baris data (perlu header + minimal 1 baris).');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $dateCol   = $this->findColumn($header, ['date', 'tanggal']);
        $amountCol = $this->findAmountColumn($header);
        if ($dateCol === null || $amountCol === null) {
            throw new DomainException('Kolom "date" atau "total_amount" tidak ditemukan di header file. Pastikan file = export "Transaksi API" dari Biteship.');
        }

        // Agregasi nominal per tanggal (jaga-jaga ada >1 baris per tanggal).
        $perDate = [];
        foreach (array_slice($rows, 1) as $i => $row) {
            $rawDate = $row[$dateCol] ?? null;
            if ($rawDate === null || $rawDate === '') continue;

            try {
                $date = $this->parseDate($rawDate);
            } catch (\Throwable $e) {
                $perDate['__err_' . $i] = ['date' => (string) $rawDate, 'amount' => 0, 'error' => 'tanggal tidak valid'];
                continue;
            }
            $amount = (float) clean_number($row[$amountCol] ?? 0);
            if (!isset($perDate[$date])) $perDate[$date] = ['date' => $date, 'amount' => 0, 'error' => null];
            $perDate[$date]['amount'] += $amount;
        }

        $created = [];
        $skipped = [];
        $errors  = [];
        $totalCreated = 0.0;

        foreach ($perDate as $entry) {
            if (!empty($entry['error'])) {
                $errors[] = ['date' => $entry['date'], 'reason' => $entry['error']];
                continue;
            }
            $res = $this->recordDay($entry['date'], $entry['amount']);
            if ($res['status'] === 'created') {
                $created[] = $res;
                $totalCreated += $res['amount'];
            } elseif ($res['status'] === 'skipped') {
                $skipped[] = $res;
            } else {
                $errors[] = ['date' => $res['date'], 'reason' => $res['reason']];
            }
        }

        return [
            'created'       => $created,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'total_created' => round($totalCreated, 2),
            'count_created' => count($created),
        ];
    }

    /** @return array{0:int,1:int} [saldoId, feeId] */
    private function accountIds(): array
    {
        $s = FreightSetting::singleton();
        if (!$s->biteship_saldo_account_id) throw new DomainException("Akun 'Saldo Biteship' belum diset di Settings → Pengaturan Ongkir.");
        if (!$s->biteship_fee_account_id)   throw new DomainException("Akun 'Beban API Biteship' belum diset di Settings → Pengaturan Ongkir.");
        return [(int) $s->biteship_saldo_account_id, (int) $s->biteship_fee_account_id];
    }

    /** Cari indeks kolom yang header-nya cocok salah satu kandidat (exact dulu, lalu contains). */
    private function findColumn(array $header, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            $idx = array_search($c, $header, true);
            if ($idx !== false) return (int) $idx;
        }
        foreach ($header as $idx => $h) {
            foreach ($candidates as $c) {
                if ($h !== '' && str_contains($h, $c)) return (int) $idx;
            }
        }
        return null;
    }

    /** Kolom nominal: utamakan 'total_amount'; hindari rates_amount/tracking_amount. */
    private function findAmountColumn(array $header): ?int
    {
        $idx = array_search('total_amount', $header, true);
        if ($idx !== false) return (int) $idx;
        // fallback: 'jumlah' (header dashboard versi Indonesia)
        $idx = array_search('jumlah', $header, true);
        if ($idx !== false) return (int) $idx;
        return null;
    }

    /** Parse tanggal dari cell raw — dukung string ISO/umum atau Excel date serial. */
    private function parseDate($raw): string
    {
        if (is_numeric($raw) && (float) $raw > 30000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
        }
        return Carbon::parse((string) $raw)->toDateString();
    }
}
