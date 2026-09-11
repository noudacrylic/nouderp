<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;

/**
 * Nama kontak percakapan — untuk mengenali lead yang belum pernah beli.
 *
 * Dua sumber, dan urutan kuasanya tegas:
 *   1. Nama yang diketik CS ('manual') — tak pernah ditimpa otomatis.
 *   2. Nama profil WhatsApp ('whatsapp') — ditanyakan ke vendor, karena webhook
 *      tidak membawanya.
 * Nama pelanggan ERP tetap di atas keduanya, tapi itu urusan
 * CrmConversation::namaTampil(), bukan kolom ini.
 *
 * Dipanggil dari DUA tempat: `crm:ambil-nama-kontak` (penjadwal) dan tombol
 * "Ambil dari WhatsApp" di popup Nama kontak.
 */
class NamaKontakService
{
    public function __construct(private ChatManager $chat)
    {
    }

    /**
     * Tanyakan nama profil WhatsApp ke vendor lalu simpan.
     *
     * `$timpaManual` hanya untuk tombol manual — CS yang sengaja menekan "Ambil
     * dari WhatsApp" memang ingin nama ketikannya diganti. Penjadwal tak
     * pernah memakainya.
     *
     * @return array{success:bool, name:?string, error:?string}
     */
    public function ambilDariWhatsapp(CrmConversation $percakapan, bool $timpaManual = false): array
    {
        if ($percakapan->channel !== 'whatsapp') {
            return ['success' => false, 'name' => null, 'error' => 'Hanya kontak WhatsApp yang punya nama profil.'];
        }

        if (! $timpaManual && $percakapan->name_source === CrmConversation::NAMA_MANUAL) {
            return ['success' => true, 'name' => $percakapan->display_name, 'error' => null];
        }

        $hasil = $this->chat->provider()->profilKontak($percakapan->contact_key);

        /*
         * name_checked_at diisi BAHKAN saat gagal. Yang membuka kesempatan
         * bertanya lagi adalah pesan masuk berikutnya (lihat
         * lengkapiYangKosong), bukan detak jam — tanpa ini, satu nomor yang
         * ditolak vendor akan ditanyakan tiap menit selamanya.
         */
        $ubah = ['name_checked_at' => now()];

        if ($hasil['success'] && $hasil['name'] !== null) {
            $ubah['display_name'] = mb_substr($hasil['name'], 0, 255);
            $ubah['name_source']  = CrmConversation::NAMA_WHATSAPP;
        }

        $percakapan->forceFill($ubah)->save();

        return $hasil;
    }

    /**
     * Isi nama untuk percakapan yang masih tanpa nama.
     *
     * Yang ditanyakan: belum pernah ditanya, ATAU ada pesan masuk sesudah
     * pertanyaan terakhir. Syarat kedua itu penting — chat yang KITA mulai
     * (template ke nomor baru) belum punya nama di vendor sampai pelanggan
     * membalas; balasan itulah yang membawa nama profilnya ke sana.
     *
     * @return array{dicoba:int, dapat:int, gagal:int}
     */
    public function lengkapiYangKosong(int $batas = 30): array
    {
        $antrean = CrmConversation::where('channel', 'whatsapp')
            ->whereNull('display_name')
            ->where(fn ($q) => $q->whereNull('name_checked_at')
                ->orWhereColumn('last_inbound_at', '>', 'name_checked_at'))
            ->orderByDesc('last_message_at')
            ->limit($batas)
            ->get();

        $dapat = 0;
        $gagal = 0;

        foreach ($antrean as $percakapan) {
            $hasil = $this->ambilDariWhatsapp($percakapan);

            if (! $hasil['success']) {
                $gagal++;
            } elseif ($hasil['name'] !== null) {
                $dapat++;
            }
        }

        return ['dicoba' => $antrean->count(), 'dapat' => $dapat, 'gagal' => $gagal];
    }

    /**
     * Simpan nama ketikan CS. Kosong = kembalikan ke nama profil WhatsApp
     * (ditanyakan ulang saat itu juga, supaya layar tak mendadak jadi nomor).
     */
    public function simpanManual(CrmConversation $percakapan, ?string $nama): void
    {
        $nama = trim((string) $nama);

        if ($nama !== '') {
            $percakapan->forceFill([
                'display_name' => mb_substr($nama, 0, 255),
                'name_source'  => CrmConversation::NAMA_MANUAL,
            ])->save();

            return;
        }

        $percakapan->forceFill([
            'display_name'    => null,
            'name_source'     => null,
            'name_checked_at' => null,
        ])->save();

        $this->ambilDariWhatsapp($percakapan);
    }
}
