<?php

namespace App\Modules\Tasks\MejaKerja\Contracts;

use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Skill Admin — pola Karyawan Virtual, tapi yang mengerjakan manusia. Skill memindai
 * pekerjaan yang terbuka di ERP (pindai), menentukan kapan task-nya selesai/batal dengan
 * sendirinya (keadaan), dan memberi tombol menuju tempat kerjanya (aksi).
 *
 * Prinsip: task selesai saat pekerjaan NYATA selesai di ERP (QC tersimpan, usulan diputus),
 * bukan karena dicentang — supaya data kinerja jujur.
 */
interface SkillAdmin
{
    public function key(): string;

    public function label(): string;

    public function deskripsi(): string;

    /** Kunci MejaKerjaPeran::PERAN */
    public function peranBawaan(): string;

    /** 1–10; makin besar makin didahulukan. */
    public function bobotBawaan(): int;

    public function slaMenitBawaan(): int;

    /** Modul pendukungnya ada (mis. QC butuh kolom is_qc)? */
    public function tersedia(): bool;

    /**
     * Pekerjaan yang saat ini terbuka, lahir sejak $sejak.
     *
     * @return iterable<array{kunci: string, judul: string, deskripsi?: ?string, taskable_type?: ?string,
     *                        taskable_id?: ?int, meta?: array, dibuat_at?: Carbon}>
     */
    public function pindai(Carbon $sejak): iterable;

    /**
     * Keadaan sumber task yang masih terbuka.
     *
     * @return array{0: 'done'|'cancelled', 1: string}|null  null = masih harus dikerjakan
     */
    public function keadaan(Task $task): ?array;

    /** @return array<int, array{label: string, url?: string, salin?: string, utama?: bool}> */
    public function aksi(Task $task): array;

    /** Boleh ditandai selesai dengan tombol? (false = hanya selesai otomatis) */
    public function selesaiManual(): bool;
}
