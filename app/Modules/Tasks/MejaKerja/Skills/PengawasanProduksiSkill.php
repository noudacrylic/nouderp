<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\PenilaianKinerjaService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Pengawasan penilaian produksi (keputusan owner 9 Nov 2026): langkah yang timernya janggal
 * dibanding target — jauh lebih cepat (< ½× jatah, curiga dikerjakan saat timer dijeda) atau
 * jauh lebih lambat (> 2×, curiga timer lupa dijeda), atau timer kosong — ditahan otomatis
 * dari penilaian, lalu kartunya masuk Meja Owner beserta buktinya. Owner memutus:
 * wajar (dihitung sesuai timer) / abaikan (tidak ikut penilaian) / tidak wajar (0 poin).
 *
 * DIAM-DIAM: karyawan tidak diberi tahu (pemberitahuan efek jera baru dibuat bila ada kasus).
 * Batas janggal = parameter "Batas perlu dicek" di Dashboard Penilaian.
 */
class PengawasanProduksiSkill implements SkillOwner, PunyaJawaban
{
    use JadiSkillManajer;

    public function key(): string { return 'pengawasan-produksi'; }

    public function label(): string { return 'Pengawasan penilaian produksi'; }

    public function deskripsi(): string
    {
        return 'Langkah produksi yang timernya janggal dibanding target (jauh lebih cepat/lambat, atau timer kosong) → kartu di Meja Owner '
            . 'berisi jatah vs timer dan riwayat pause. Owner memutus Wajar / Abaikan / Tidak wajar; sampai diputus, poinnya ditahan. '
            . 'Karyawan tidak diberi tahu.';
    }

    /** Diminta owner (lanjut skill pengawasan 20 Nov); tetap berlabel percobaan (config). */
    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 5; }

    /** Cukup diputus sebelum penilaian bulan itu dipakai. */
    public function slaMenitBawaan(): int { return 3 * 24 * 60; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'langkah'; }

    public function pindai(Carbon $sejak): iterable
    {
        // Bulan lalu & bulan ini — periode penilaian yang masih bisa berubah.
        $mulai = now()->subMonthNoOverflow()->startOfMonth();

        foreach (app(PenilaianKinerjaService::class)->perluDiputus($mulai) as $o) {
            $orang = collect($o['pelaksana'])->pluck('name')->implode(', ') ?: 'tanpa pelaksana';
            yield [
                'kunci'         => "pengawasan:step:{$o['step_id']}",
                'judul'         => "Timer janggal: {$o['op']} — {$orang} (" . $this->ringkasRasio($o) . ')',
                'deskripsi'     => $this->bukti($o),
                'taskable_type' => ProductionOrderStep::class,
                'taskable_id'   => $o['step_id'],
                'meta'          => ['op_id' => $o['op_id'], 'bulan' => $o['selesai']->format('Y-m')],
                'dibuat_at'     => $o['selesai']->copy()->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $step = ProductionOrderStep::find($task->taskable_id, ['id', 'status', 'penilaian_putusan']);
        if (!$step || $step->status !== 'completed') return ['cancelled', 'Langkah tidak lagi selesai (dikembalikan/dihapus).'];
        if ($step->penilaian_putusan) {
            return ['done', 'Diputus: ' . (PenilaianKinerjaService::PUTUSAN[$step->penilaian_putusan] ?? $step->penilaian_putusan) . '.'];
        }
        $o = app(PenilaianKinerjaService::class)->nilaiSatuLangkah((int) $task->taskable_id);
        if (!$o || $o['status'] !== 'cek') return ['cancelled', 'Tidak lagi janggal (standar / batas penilaian berubah).'];

        return null;
    }

    public function aksi(Task $task): array
    {
        $meta = $task->meta ?? [];
        $aksi = [];
        if (!empty($meta['op_id'])) {
            $aksi[] = ['label' => 'Lihat OP', 'url' => route('production.orders.show', $meta['op_id']), 'utama' => true];
        }
        $aksi[] = ['label' => 'Dashboard Penilaian', 'url' => route('production.waktu-standar.penilaian', array_filter(['dari' => $meta['bulan'] ?? null, 'sampai' => $meta['bulan'] ?? null]))];

        return $aksi;
    }

    public function selesaiManual(): bool { return false; }

    public function jawaban(Task $task): array
    {
        return [
            ['kunci' => 'wajar', 'label' => '✅ Wajar', 'utama' => true],
            ['kunci' => 'abaikan', 'label' => '➖ Abaikan'],
            ['kunci' => 'curang', 'label' => '⚠️ Tidak wajar'],
        ];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        app(PenilaianKinerjaService::class)->putuskan((int) $task->taskable_id, $kunci, $u->id);

        return 'Putusan disimpan: ' . (PenilaianKinerjaService::PUTUSAN[$kunci] ?? $kunci) . '.';
    }

    private function ringkasRasio(array $o): string
    {
        if ($o['rasio'] === null) return 'timer kosong';

        return round($o['rasio'] * 100) . '% dari target';
    }

    /** Bukti di kartu: isi OP, target vs timer, riwayat timer lengkap dengan alasan pause. */
    private function bukti(array $o): string
    {
        $isi = collect($o['rincian'])->map(fn ($r) => rtrim(rtrim(number_format($r['qty'], 2, ',', ''), '0'), ',') . '× ' . $r['sku'])->implode(', ');
        $baris = [
            "Isi: {$isi}",
            'Target ' . PenilaianKinerjaService::teksMenit((float) $o['jatah']) . ' · timer ' . PenilaianKinerjaService::teksMenit((float) $o['menit'])
                . ($o['rasio'] !== null ? ' (' . round($o['rasio'] * 100) . '%)' : '') . ' — ' . ($o['alasan'] ?? 'janggal'),
            'Selesai ' . $o['selesai']->translatedFormat('j M H:i'),
            'Riwayat timer:',
        ];

        $label = [
            'started' => '▶ Mulai', 'resumed' => '▶ Lanjut', 'auto_resumed' => '▶ Lanjut otomatis',
            'paused' => '⏸ Pause', 'auto_paused' => '⏸ Jeda otomatis (jadwal)', 'completed' => '✔ Selesai',
        ];
        foreach (ProductionStepTimeLog::where('production_order_step_id', $o['step_id'])->orderBy('occurred_at')->orderBy('id')->get() as $l) {
            $baris[] = '  ' . $l->occurred_at->translatedFormat('j M H:i') . ' ' . ($label[$l->event_type] ?? $l->event_type)
                . ($l->alasan ? ' — ' . $l->labelAlasan() : '') . ($l->notes ? ': ' . $l->notes : '');
        }

        return implode("\n", $baris);
    }
}
