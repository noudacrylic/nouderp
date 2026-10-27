<?php

namespace App\Modules\Tasks\MejaKerja\Contracts;

use App\Models\User;
use App\Modules\Tasks\Models\Task;

/**
 * Skill yang task-nya diselesaikan dengan JAWABAN pilihan (mis. hasil cek persediaan:
 * "Masih ada" / "Perlu beli"), bukan tombol Selesai — untuk pekerjaan yang hasilnya
 * keputusan manusia, tak terlihat di dokumen ERP. Jawaban tercatat di meta task.
 */
interface PunyaJawaban
{
    /** @return array<int, array{kunci: string, label: string, utama?: bool}> kosong = tak ada jawaban saat ini */
    public function jawaban(Task $task): array;

    /** Terapkan jawaban; kembalikan pesan untuk pengguna. */
    public function jawab(Task $task, string $kunci, User $u): string;
}
