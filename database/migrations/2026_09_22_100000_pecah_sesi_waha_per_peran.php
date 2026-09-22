<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu container WAHA melayani lebih dari satu nomor.
 *
 * Sampai sekarang baris `crm_settings(waha)` menyimpan satu nama sesi dan satu
 * jejak pemeriksaan, datar di akar `config` — bentuk yang benar selama hanya
 * ada nomor notifikasi. Begitu nomor utama ikut ditautkan, kunci-kunci datar
 * itu jadi rebutan: siapa pun yang memeriksa terakhir menimpa status milik
 * nomor yang lain, dan layar menampilkan keadaan nomor yang salah.
 *
 * Nilai lama DIPINDAH, bukan dibiarkan di tempatnya sambil dibaca sebagai
 * cadangan. Membiarkannya berarti dua sumber kebenaran untuk satu pertanyaan,
 * dan modul ini sudah pernah membayar mahal untuk itu.
 */
return new class extends Migration
{
    /** Kunci yang dulu datar di akar config, kini milik satu peran. */
    private const KUNCI_SESI = ['session', 'last_status', 'last_checked_at', 'alerted_at'];

    private const PERAN_LAMA = 'notifikasi';

    public function up(): void
    {
        $this->pindahkan(fn (array $config) => $this->keBawahPeran($config));
    }

    public function down(): void
    {
        $this->pindahkan(fn (array $config) => $this->kembaliDatar($config));
    }

    private function pindahkan(callable $ubah): void
    {
        $baris = DB::table('crm_settings')->where('provider', 'waha')->first();

        if (! $baris) {
            return;
        }

        $config = json_decode((string) ($baris->config ?? ''), true);

        if (! is_array($config)) {
            return;
        }

        DB::table('crm_settings')
            ->where('id', $baris->id)
            ->update(['config' => json_encode($ubah($config))]);
    }

    private function keBawahPeran(array $config): array
    {
        $sesi = is_array($config['sesi'] ?? null) ? $config['sesi'] : [];
        $lama = is_array($sesi[self::PERAN_LAMA] ?? null) ? $sesi[self::PERAN_LAMA] : [];

        foreach (self::KUNCI_SESI as $kunci) {
            if (array_key_exists($kunci, $config)) {
                /*
                 * Nilai yang sudah ada di bawah peran TIDAK ditimpa. Migrasi
                 * yang dijalankan dua kali (atau data yang terlanjur disentuh
                 * kode baru) tidak boleh memundurkan status ke nilai usang.
                 */
                $lama[$kunci] ??= $config[$kunci];
                unset($config[$kunci]);
            }
        }

        /*
         * 'pernah_tertaut' belum pernah ada sebelum ini, jadi harus DISIMPULKAN
         * — kalau tidak, nomor yang nyata-nyata sedang WORKING akan berlabel
         * "belum pernah ditautkan" di layar, dan pemiliknya akan memindai QR
         * untuk sesi yang sebenarnya sehat. Status WORKING adalah bukti
         * penautan yang sudah terjadi; tak ada cara lain nomor sampai ke sana.
         */
        if (($lama['last_status'] ?? null) === 'WORKING') {
            $lama['pernah_tertaut'] = true;
        }

        if ($lama !== []) {
            $sesi[self::PERAN_LAMA] = $lama;
            $config['sesi'] = $sesi;
        }

        return $config;
    }

    private function kembaliDatar(array $config): array
    {
        $lama = is_array($config['sesi'][self::PERAN_LAMA] ?? null) ? $config['sesi'][self::PERAN_LAMA] : [];

        foreach (self::KUNCI_SESI as $kunci) {
            if (array_key_exists($kunci, $lama)) {
                $config[$kunci] = $lama[$kunci];
            }
        }

        unset($config['sesi']);

        return $config;
    }
};
