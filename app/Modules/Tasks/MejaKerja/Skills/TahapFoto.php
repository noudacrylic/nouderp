<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;

/**
 * Tahap terakhir kartu desain / cetak-tempel / foto custom (keputusan 16 Nov 2026): barang yang
 * wajib difoto (config galeri.wajib_foto) baru boleh ditutup setelah minimal satu foto tertaut
 * pesanan. Tanpa foto hanya lewat "⏭ Lewati foto" dengan alasan yang tercatat (20 Nov 2026:
 * mis. KS tanpa stiker tak perlu difoto).
 *
 * "Sudah dikirim" sengaja BUKAN alasan pilihan (18 Nov 2026): pengiriman terdeteksi sendiri dari
 * Surat Jalan dan menutup kartu otomatis; sebagai tombol, ia dipakai menutup kartu yang barangnya
 * belum dikirim.
 *
 * Tahap kartu disimpan di meta 'tahap'; lencana jenis kartu mengikutinya (MejaKerjaService::jenisKartu).
 */
trait TahapFoto
{
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
        return [['kunci' => 'foto-selesai', 'label' => '✓ Foto selesai', 'utama' => true]];
    }

    /** Jawaban tahap foto; null bila kuncinya bukan milik tahap foto. */
    protected function jawabFoto(Task $task, string $kunci, User $u, int $soId): ?string
    {
        if ($kunci !== 'foto-selesai') return null;

        if ($kurang = app(FotoProdukService::class)->kekuranganFoto($soId)) throw new \RuntimeException($kurang);
        app(MejaKerjaService::class)->ubahStatus($task, 'done', $u, 'Foto pesanan lengkap');
        return 'Foto tersimpan — kartu selesai.';
    }

    /** "⏭ Lewati foto": kartu ditutup tanpa foto, alasannya tercatat di pesanan & riwayat kartu. */
    public function lewatiFoto(Task $task, User $u, string $alasan): void
    {
        $soId = $this->soUntukFoto($task);
        if ($soId) app(FotoProdukService::class)->tandaiTanpaFoto($soId, $alasan, $u->id);
        app(MejaKerjaService::class)->ubahStatus($task, 'done', $u, "Tanpa foto: $alasan");
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

    /** Folder porto pesanan di Galeri PWA (cadangan: unggah langsung dari HP ke foldernya). */
    protected function aksiFoto(int $soId): array
    {
        return ['label' => '📁 Folder porto', 'url' => route('cs.galeri', ['so' => $soId])];
    }

    /** Pesanan yang difoto kartu ini. */
    abstract public function soUntukFoto(Task $task): ?int;

    /**
     * Tahap foto: tombol "🖼 Gambar belum dilampirkan" → pilih dari Kotak Porto (unggahan HP),
     * potong/putar, simpan → pindah ke NAS & kartu selesai. Seperti nota: wajib ada lampirannya,
     * kecuali dilewati dengan alasan.
     */
    public function formKartu(Task $task): ?array
    {
        if ($this->tahap($task) !== 'foto' || !$this->soUntukFoto($task)) return null;

        return [
            'jenis'   => 'porto',
            'label'   => '🖼 Gambar belum dilampirkan',
            'url'     => route('tasks.meja.porto.lampirkan', $task->id),
            'lewati'  => route('tasks.meja.porto.lewati', $task->id),
            'alasan'  => config('galeri.alasan_lewati_foto', []),
            'judul'   => ($task->meta ?? [])['order_number'] ?? $task->title,
        ];
    }
}
