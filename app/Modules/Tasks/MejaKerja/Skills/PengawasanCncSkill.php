<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Production\Models\ProductionMesinKeadaan;
use App\Modules\Production\Services\PenilaianCncService;
use App\Modules\Production\Services\PenilaianKinerjaService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Pengawasan penilaian operator CNC (disetujui owner 28 Nov 2026): mesin selesai memotong lalu
 * dibiarkan > 30 menit (jam kerja, ada pekerjaan, sesudah aturan antre & Pending) → poinnya
 * ditahan, kartu masuk Meja Owner. Owner memutus Wajar (dihitung sesuai waktunya = 0 poin
 * karena > 8 mnt) / Abaikan (tidak ikut penilaian, mis. mesin rusak, tugas lain) / Tidak wajar.
 */
class PengawasanCncSkill implements SkillOwner, PunyaJawaban
{
    use JadiSkillManajer;

    public function key(): string { return 'pengawasan-cnc'; }

    public function label(): string { return 'Pengawasan penilaian operator CNC'; }

    public function deskripsi(): string
    {
        return 'Mesin CNC selesai memotong lalu dibiarkan lebih dari 30 menit di jam kerja padahal ada pekerjaan → kartu di Meja Owner '
            . 'dengan jam selesai & jalan lagi. Owner memutus Wajar / Abaikan / Tidak wajar; sampai diputus, poinnya ditahan.';
    }

    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 5; }

    public function slaMenitBawaan(): int { return 3 * 24 * 60; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'kejadian'; }

    public function pindai(Carbon $sejak): iterable
    {
        foreach (app(PenilaianCncService::class)->perluDiputus(now()->subMonthNoOverflow()->startOfMonth()) as $k) {
            yield [
                'kunci'         => "pengawasan-cnc:{$k['row_id']}",
                'judul'         => "Mesin dibiarkan: {$k['mesin']} — {$k['operator']} (" . PenilaianKinerjaService::teksMenit($k['detik'] / 60) . ')',
                'deskripsi'     => $this->bukti($k),
                'taskable_type' => ProductionMesinKeadaan::class,
                'taskable_id'   => $k['row_id'],
                'meta'          => ['bulan' => $k['selesai']->format('Y-m'), 'orang' => $k['operator_id']],
                'dibuat_at'     => $k['mulai']->copy()->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $row = ProductionMesinKeadaan::find($task->taskable_id, ['id', 'putusan']);
        if (!$row) return ['cancelled', 'Data sinyal tidak ada lagi.'];
        if ($row->putusan) return ['done', 'Diputus: ' . (PenilaianKinerjaService::PUTUSAN[$row->putusan] ?? $row->putusan) . '.'];
        $k = app(PenilaianCncService::class)->kejadian((int) $task->taskable_id);
        if (!$k || $k['status'] !== 'cek') return ['cancelled', 'Tidak lagi melewati batas 30 menit.'];

        return null;
    }

    public function aksi(Task $task): array
    {
        $meta = $task->meta ?? [];

        return [['label' => 'Penilaian Operator CNC', 'utama' => true,
            'url' => route('production.waktu-standar.cnc', array_filter(['orang' => $meta['orang'] ?? null, 'bulan' => $meta['bulan'] ?? null]))]];
    }

    public function selesaiManual(): bool { return false; }

    public function jawaban(Task $task): array
    {
        return [
            ['kunci' => 'wajar', 'label' => '✅ Wajar (dinilai 0 poin)', 'utama' => true],
            ['kunci' => 'abaikan', 'label' => '➖ Abaikan'],
            ['kunci' => 'curang', 'label' => '⚠️ Tidak wajar'],
        ];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        app(PenilaianCncService::class)->putuskan((int) $task->taskable_id, $kunci, $u->id);
        $teks = PenilaianKinerjaService::PUTUSAN[$kunci] ?? $kunci;
        app(\App\Modules\Tasks\MejaKerja\MejaKerjaService::class)->ubahStatus($task, 'done', $u, "Diputus: {$teks}.");

        return "Putusan disimpan: {$teks}.";
    }

    private function bukti(array $k): string
    {
        $baris = [
            "{$k['mesin']} selesai memotong " . $k['selesai']->translatedFormat('j M H:i') . ', memotong lagi ' . $k['mulai']->translatedFormat('j M H:i') . '.',
            'Dihitung ' . PenilaianKinerjaService::teksMenit($k['detik'] / 60) . ' (jam kerja, ada pekerjaan, tanpa Pending beralasan)'
                . ($k['antre'] > 0 ? '; ' . PenilaianKinerjaService::teksMenit($k['antre'] / 60) . ' dimaafkan karena melayani mesin lain' : '') . '.',
            'Wajar = tetap dinilai (0 poin). Abaikan = tidak ikut penilaian (mis. mesin rusak, disuruh tugas lain). Tidak wajar = 0 poin.',
        ];

        return implode("\n", $baris);
    }
}
