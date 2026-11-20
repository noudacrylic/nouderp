<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Koreksi timer langkah produksi oleh owner — untuk kasus pelaksana lupa menekan Pending
 * (mis. lembur bongkar akrilik sementara timer OP terus jalan). Owner memilih rentang jam;
 * bagian rentang yang jatuh di waktu timer berjalan disisipi log `paused` (beralasan) dan
 * `resumed`, persis seperti kalau pelaksana menekan Pending saat itu. Log asli tidak diubah,
 * jadi koreksi bisa dibatalkan utuh lewat grupnya.
 */
class KoreksiTimerService
{
    private const MULAI = ['started', 'resumed', 'auto_resumed'];
    private const HENTI = ['paused', 'auto_paused', 'completed'];

    /**
     * Potongan waktu timer berjalan, dihitung sama dengan elapsed_working_seconds.
     *
     * @return array<int, array{mulai: Carbon, selesai: ?Carbon, detik: int}>  selesai null = masih jalan
     */
    public function segmen(ProductionOrderStep $step): array
    {
        $logs = ProductionStepTimeLog::where('production_order_step_id', $step->id)
            ->orderBy('occurred_at')->orderBy('id')->get();
        $hasil = [];
        $start = null;
        $raw = null;
        foreach ($logs as $log) {
            if (in_array($log->event_type, self::MULAI, true)) {
                $raw = $log->occurred_at;
                $start = ($log->event_type === 'started' && $step->started_effective_at)
                    ? Carbon::parse($step->started_effective_at) : $log->occurred_at;
            } elseif ($start && in_array($log->event_type, self::HENTI, true)) {
                if ($log->occurred_at->lt($start)) $start = $raw;
                $hasil[] = ['mulai' => $start->copy(), 'selesai' => $log->occurred_at->copy(),
                    'detik' => max(0, (int) $start->diffInSeconds($log->occurred_at))];
                $start = $raw = null;
            }
        }
        if ($start && $step->status === 'in_progress') {
            $hasil[] = ['mulai' => $start->copy(), 'selesai' => null, 'detik' => max(0, (int) $start->diffInSeconds(now()))];
        }

        return $hasil;
    }

    /**
     * Keluarkan rentang [dari, sampai] dari timer. Mengembalikan detik yang dikeluarkan.
     *
     * @throws InvalidArgumentException rentang tidak sah / tidak menyentuh waktu timer berjalan
     */
    public function keluarkan(int $stepId, Carbon $dari, Carbon $sampai, string $alasan, ?string $keterangan, ?int $userId): int
    {
        if (!isset(ProductionStepTimeLog::ALASAN_PENDING[$alasan])) {
            throw new InvalidArgumentException('Alasan tidak dikenal.');
        }
        if ($sampai->lte($dari)) {
            throw new InvalidArgumentException('Jam "sampai" harus setelah jam "dari".');
        }
        if ($sampai->gt(now())) {
            throw new InvalidArgumentException('Rentang tidak boleh melewati jam sekarang.');
        }

        return DB::transaction(function () use ($stepId, $dari, $sampai, $alasan, $keterangan, $userId) {
            $step = ProductionOrderStep::lockForUpdate()->findOrFail($stepId);
            $grup = (string) Str::ulid();
            $catatan = trim('Koreksi owner' . ($keterangan ? ': ' . $keterangan : ''));
            $total = 0;

            foreach ($this->segmen($step) as $s) {
                $a = $dari->gt($s['mulai']) ? $dari->copy() : $s['mulai']->copy();
                $ujung = $s['selesai'] ?? now();
                $b = $sampai->lt($ujung) ? $sampai->copy() : $ujung->copy();
                if ($b->lte($a)) continue;

                $total += (int) $a->diffInSeconds($b);
                $this->log($step->id, 'paused', $a, $alasan, $catatan, $grup, $userId);
                // Segmen ditutup log aslinya bila rentang menutup sampai ujungnya.
                if ($s['selesai'] === null || $sampai->lt($s['selesai'])) {
                    $this->log($step->id, 'resumed', $b, null, $catatan, $grup, $userId);
                }
            }

            if ($total === 0) {
                throw new InvalidArgumentException('Tidak ada waktu timer berjalan di rentang itu.');
            }

            return $total;
        });
    }

    /** Batalkan satu koreksi utuh (semua log grupnya). */
    public function batalkan(int $stepId, string $grup): int
    {
        return ProductionStepTimeLog::where('production_order_step_id', $stepId)
            ->where('koreksi_grup', $grup)->delete();
    }

    private function log(int $stepId, string $jenis, Carbon $pada, ?string $alasan, string $catatan, string $grup, ?int $userId): void
    {
        ProductionStepTimeLog::create([
            'production_order_step_id' => $stepId,
            'event_type'   => $jenis,
            'alasan'       => $alasan,
            'occurred_at'  => $pada,
            'notes'        => $catatan,
            'koreksi_grup' => $grup,
            'koreksi_by'   => $userId,
        ]);
    }
}
