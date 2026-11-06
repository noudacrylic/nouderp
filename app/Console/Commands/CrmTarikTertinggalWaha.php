<?php

namespace App\Console\Commands;

use App\Models\CrmSetting;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Support\PeranWaha;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Pelaksana tombol "Tarik pesan tertinggal" di Pengaturan → WAHA (nomor utama).
 *
 * Pesan yang masuk saat sesi WAHA terputus tidak pernah lewat webhook — WhatsApp
 * menyerahkannya sebagai sinkron riwayat saat perangkat tersambung lagi (kejadian
 * 6 Nov 2026). Tombolnya hanya MENCATAT permintaan; perintah ini (tiap menit) yang
 * menjalankan crm:impor-cermin-waha, supaya halaman tidak menggantung selama impor
 * dan klik berulang tetap jadi satu impor.
 *
 * Jendela impor mulai dari tanggal pesan WAHA terakhir yang sudah terekam (awal
 * celah), paling jauh 7 hari ke belakang. Aman diulang: pesan yang sudah ada ditolak
 * oleh provider_message_id yang unik.
 */
class CrmTarikTertinggalWaha extends Command
{
    protected $signature = 'crm:tarik-tertinggal-waha';

    protected $description = 'Jalankan permintaan "Tarik pesan tertinggal" nomor utama WAHA (dari tombol Pengaturan)';

    public function handle(): int
    {
        $waha = CrmSetting::for('waha');
        if (empty($waha->sesi(PeranWaha::UTAMA)['tarik_diminta_at'])) {
            return self::SUCCESS;
        }

        $sejak = self::sejak();
        $waha->simpanSesi(PeranWaha::UTAMA, ['tarik_diminta_at' => null, 'tarik_mulai_at' => now()->toIso8601String(), 'tarik_sejak' => $sejak]);

        // Hasil dihitung dari basis data, bukan dari teks keluaran perintah impor.
        $hitung = fn () => DB::table('crm_messages as m')->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.channel', CrmConversation::KANAL_CERMIN)->count();
        $awal = $hitung();
        $kode = Artisan::call('crm:impor-cermin-waha', ['--sejak' => $sejak, '--media' => true]);
        $baru = $hitung() - $awal;

        $hasil = $kode === self::SUCCESS
            ? "{$baru} pesan baru ditarik (chat aktif sejak " . Carbon::parse($sejak)->format('d/m') . ').'
            : 'Gagal — sesi nomor utama belum WORKING. Tautkan dulu, lalu klik lagi.';

        $waha->refresh()->simpanSesi(PeranWaha::UTAMA, [
            'tarik_selesai_at' => now()->toIso8601String(),
            'tarik_hasil'      => $hasil,
        ]);
        $this->line($hasil);

        return self::SUCCESS;
    }

    /**
     * Awal jendela impor (Y-m-d): minimal sejak kemarin — pesan baru yang sudah lewat
     * webhook menggeser "pesan terakhir" ke hari ini padahal celahnya lebih awal — dan mundur
     * ke pesan WAHA terakhir yang terekam bila lebih lama, paling jauh 7 hari.
     */
    public static function sejak(): string
    {
        $terakhir = DB::table('crm_messages as m')->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.channel', CrmConversation::KANAL_CERMIN)->max('m.sent_at');
        $awal = now()->subDay();
        if ($terakhir && Carbon::parse($terakhir)->lt($awal)) {
            $awal = Carbon::parse($terakhir);
        }

        return $awal->max(now()->subDays(7))->toDateString();
    }
}
