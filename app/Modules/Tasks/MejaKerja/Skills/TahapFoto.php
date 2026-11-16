<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;

/**
 * Tahap terakhir kartu desain / cetak-tempel / foto custom (keputusan 16 Nov 2026): barang yang
 * wajib difoto (config galeri.wajib_foto) baru boleh ditutup setelah info foto terisi dan minimal
 * satu foto tertaut pesanan. Tanpa foto hanya lewat alasan yang tercatat.
 *
 * Tahap kartu disimpan di meta 'tahap'; lencana jenis kartu mengikutinya (MejaKerjaService::jenisKartu).
 */
trait TahapFoto
{
    private static array $ALASAN_TANPA_FOTO = [
        'sudah-dikirim' => 'Barang sudah dikirim sebelum difoto',
        'kamera'        => 'HP/kamera bermasalah',
    ];

    protected function tahap(Task $task): ?string
    {
        return ($task->meta ?? [])['tahap'] ?? null;
    }

    protected function keTahap(Task $task, string $tahap): void
    {
        $task->meta = array_merge($task->meta ?? [], ['tahap' => $tahap]);
        $task->save();
    }

    protected function jawabanFoto(): array
    {
        $j = [['kunci' => 'foto-selesai', 'label' => '✓ Foto selesai', 'utama' => true]];
        foreach (self::$ALASAN_TANPA_FOTO as $k => $label) {
            $j[] = ['kunci' => "tanpa-foto:$k", 'label' => "Tak bisa difoto: " . lcfirst($label)];
        }
        return $j;
    }

    /** Jawaban tahap foto; null bila kuncinya bukan milik tahap foto. */
    protected function jawabFoto(Task $task, string $kunci, User $u, int $soId): ?string
    {
        $foto = app(FotoProdukService::class);
        $meja = app(MejaKerjaService::class);

        if ($kunci === 'foto-selesai') {
            if ($kurang = $foto->kekuranganFoto($soId)) throw new \RuntimeException($kurang);
            $meja->ubahStatus($task, 'done', $u, 'Foto pesanan lengkap');
            return 'Foto tersimpan — kartu selesai.';
        }

        if (str_starts_with($kunci, 'tanpa-foto:')) {
            $alasan = self::$ALASAN_TANPA_FOTO[substr($kunci, 11)] ?? null;
            if (!$alasan) return null;
            $foto->tandaiTanpaFoto($soId, $alasan, $u->id);
            $meja->ubahStatus($task, 'done', $u, "Tanpa foto: $alasan");
            return "Ditutup tanpa foto ($alasan).";
        }

        return null;
    }

    /** Selesai tahap kerja sebelumnya: masuk tahap foto bila wajib & belum lengkap, kalau tidak langsung selesai. */
    protected function lanjutKeFotoAtauSelesai(Task $task, User $u, ?int $soId, string $catatanSelesai): bool
    {
        $foto = app(FotoProdukService::class);
        if ($soId && $foto->wajibFoto($soId) && $foto->kekuranganFoto($soId)) {
            $this->keTahap($task, 'foto');
            return true;
        }
        app(MejaKerjaService::class)->ubahStatus($task, 'done', $u, $catatanSelesai);
        return false;
    }

    protected function aksiFoto(int $soId): array
    {
        return ['label' => '📷 Foto produk', 'url' => route('cs.galeri', ['so' => $soId]), 'utama' => true];
    }
}
