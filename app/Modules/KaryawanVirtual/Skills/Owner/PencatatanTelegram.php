<?php

namespace App\Modules\KaryawanVirtual\Skills\Owner;

use App\Modules\KaryawanVirtual\Contracts\Skill;

/**
 * Pembungkus asisten pencatat Telegram (AiAccountantService). Bekerja saat ada pesan masuk, bukan
 * terjadwal: mode menentukan bot boleh mencatat atau tidak, dan tiap posting masuk jurnal.
 */
class PencatatanTelegram implements Skill
{
    public const KEY = 'owner.pencatatan-telegram';

    public function label(): string { return 'Pencatatan via Telegram'; }

    public function deskripsi(): string
    {
        return 'Mencatat pengeluaran, transfer, bayar ongkir, dan DP dari chat atau foto struk di Telegram. Tiap transaksi yang diposting masuk jurnal kerja.';
    }

    public function jadwal(): string { return 'Saat ada pesan Telegram'; }

    public function satuan(): string { return 'pencatatan'; }

    public function modeTersedia(): array { return [self::NONAKTIF, self::OTOMATIS]; }

    /** Sudah dipakai sehari-hari sebelum jadi skill — bawaannya tetap jalan. */
    public function modeBawaan(): string { return self::OTOMATIS; }

    public function alasanTerkunci(): ?string { return null; }

    public function jalankan(bool $simulasi): array { return []; }

    public function rincian(array $hasil): array { return $hasil; }
}
