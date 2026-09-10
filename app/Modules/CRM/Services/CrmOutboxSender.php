<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\NotificationManager;
use App\Modules\CRM\Support\TemplateResmi;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim antrean notifikasi. Dipanggil terjadwal (crm:kirim-notifikasi) dan
 * kelak oleh tombol "Kirim Sekarang" di layar pesanan.
 *
 * Berbicara ke NotificationManager, bukan ke ChatManager langsung: jalur mana
 * yang dipakai (template Meta berbayar atau WAHA self-host) adalah urusan
 * pengaturan, dan berkas ini tidak boleh tahu bedanya.
 *
 * Selama saklar jangan-kirim menyala, yang diserahkan manager adalah driver
 * palsu — baris tetap ditandai terkirim dan alurnya teruji penuh, tapi tak
 * sebutir pun paket keluar. Itu memang yang diinginkan sampai Tahap 6.
 */
class CrmOutboxSender
{
    public function __construct(
        private NotificationManager $notifikasi,
        private CrmReplyService $balasan,
    )
    {
    }

    /** Kirim semua yang jatuh tempo. @return array{terkirim:int, gagal:int, tertahan:int} */
    public function kirimYangJatuhTempo(int $limit = 50): array
    {
        $hasil = ['terkirim' => 0, 'gagal' => 0, 'tertahan' => 0];

        $antrean = CrmOutboxMessage::jatuhTempo()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($antrean as $baris) {
            $hasil[$this->kirimSatuDenganStatus($baris)]++;
        }

        return $hasil;
    }

    public function kirimSatu(CrmOutboxMessage $baris): bool
    {
        return $this->kirimSatuDenganStatus($baris) === 'terkirim';
    }

    /** @return 'terkirim'|'gagal'|'tertahan' */
    private function kirimSatuDenganStatus(CrmOutboxMessage $baris): string
    {
        if (blank($baris->recipient) || blank($baris->template_name)) {
            $baris->tandaiGagal('Baris outbox tidak lengkap (nomor atau nama template kosong).');

            return 'gagal';
        }

        /*
         * Pancingan menyimpang dari seluruh alur di bawah, dan alasannya bukan
         * kerapian melainkan HARGA. Jalurnya tidak ditentukan driver melainkan
         * jendela pelanggan: selama masih terbuka ia berangkat sebagai pesan
         * sesi bertombol yang GRATIS; sesudah tutup, satu-satunya yang sah
         * adalah template berbayar. Keputusan itu sudah ada di satu tempat
         * (CrmReplyService::kirimPancingan), dan menyalinnya ke sini berarti
         * dua tempat yang bisa memilih jalur berbeda untuk pesan yang sama.
         *
         * Bonusnya kebetulan tepat: baris yang tertahan tiga jam karena WAHA
         * mati akan menemukan jendelanya sudah tutup saat akhirnya dikirim, dan
         * dengan sendirinya naik ke jalur berbayar — persis eskalasi yang
         * berlaku untuk notifikasi lain, tanpa satu baris pun aturan tambahan.
         */
        if ($baris->event === CrmOutboxMessage::EVENT_PANCINGAN) {
            return $this->kirimPancingan($baris);
        }

        /*
         * Sebagian pesan TIDAK boleh ikut driver yang sedang dipilih.
         *
         * Pengingat jatuh tempo selalu lewat jalur resmi walau seluruh sistem
         * sedang memakai WAHA: pesan soal utang yang jatuh tempo adalah yang
         * paling mudah disalahartikan sebagai penipuan, dan dari nomor tanpa
         * centang ia menempatkan pelanggan pada pilihan yang sama-sama buruk —
         * mengabaikan tagihan yang sah, atau memercayai pesan yang tak bisa ia
         * verifikasi. Untuk yang satu ini kita membayar template.
         */
        $jalur = TemplateResmi::wajibResmi((string) $baris->template_name)
            ? $this->notifikasi->resmi()
            : $this->notifikasi->provider();

        $hasil = $jalur->kirimNotifikasi([
            'to'        => $baris->recipient,
            'template'  => $baris->template_name,
            'language'  => 'id',
            'body'      => (array) $baris->template_body,
            'url_lacak' => $this->urlLacak($baris),
        ]);

        if ($hasil['success'] ?? false) {
            $baris->tandaiTerkirim($hasil['message_id'] ?? null);

            return 'terkirim';
        }

        $alasan = (string) ($hasil['error'] ?? 'Gagal tanpa keterangan.');

        if ($hasil['tahan'] ?? false) {
            return $this->tahanAtauEskalasi($baris, $alasan);
        }

        $baris->tandaiGagal($alasan);
        Log::warning('[CRM] notifikasi gagal terkirim', ['outbox_id' => $baris->id, 'error' => $alasan]);

        return 'gagal';
    }

    /**
     * @return 'terkirim'|'gagal'
     */
    private function kirimPancingan(CrmOutboxMessage $baris): string
    {
        $percakapan = $baris->conversation_id
            ? CrmConversation::find($baris->conversation_id)
            : null;

        if (! $percakapan) {
            $baris->tandaiGagal('Percakapannya sudah tidak ada — pancingan tidak punya tujuan.');

            return 'gagal';
        }

        $hasil = $this->balasan->kirimPancingan($percakapan, null, true);

        if ($hasil['success']) {
            $baris->tandaiTerkirim(
                $hasil['message']?->provider_message_id,
                // Jalurnya DICATAT, karena inilah satu-satunya keterangan yang
                // membedakan pancingan gratis dari yang berbayar setelah
                // kejadiannya lewat.
                $percakapan->windowIsOpen() ? 'Jalur sesi (gratis).' : 'Jendela sudah tutup — lewat template berbayar.'
            );

            return 'terkirim';
        }

        $baris->tandaiGagal((string) $hasil['error']);

        Log::warning('[CRM] pancingan gagal terkirim', [
            'outbox_id' => $baris->id, 'error' => $hasil['error'],
        ]);

        return 'gagal';
    }

    /**
     * TERTAHAN, bukan gagal: sesi WhatsApp yang sedang mati adalah keadaan
     * sementara yang lumrah, dan pulihnya butuh manusia menscan QR. Kalau baris
     * ini ditandai gagal, ia keluar dari antrean selamanya — dan pelanggan tak
     * pernah dapat kabar meski sesinya pulih semenit kemudian.
     *
     * Tapi menahan pun ada batasnya. Kabar "pesanan Anda sudah dikirim" yang
     * datang sehari kemudian sama tak bergunanya dengan tidak datang sama
     * sekali. Karena itu lewat batas, pesannya dialihkan ke template Meta
     * berbayar — kita membayar justru pada saat yang murah sedang tidak bisa
     * dipakai, dan itu memang harga yang pantas.
     *
     * @return 'terkirim'|'tertahan'|'gagal'
     */
    private function tahanAtauEskalasi(CrmOutboxMessage $baris, string $alasan): string
    {
        $batas = (int) config('crm.notifikasi.tahan_maks_jam', 3);

        /*
         * Sebagian pesan TIDAK punya padanan template di Meta (kabar "stok
         * sudah ada"). Mengeskalasikannya ke jalur berbayar berarti mengirim
         * nama template yang tak dikenal di sana: pasti ditolak, dan alasannya
         * terbaca seperti gangguan jalur padahal bukan. Ia ditahan seperti
         * biasa, lalu lewat batas dinyatakan GAGAL dengan alasan yang jujur —
         * supaya adminnya tahu pesan ini perlu dikirim tangan.
         */
        if (TemplateResmi::hanyaWaha((string) $baris->template_name)) {
            $baris->tandaiTertahan($alasan, now()->addMinutes((int) config('crm.notifikasi.tahan_jeda_menit', 10)));

            if ($batas <= 0 || ! $baris->tertahanLebihDari($batas)) {
                return 'tertahan';
            }

            $baris->tandaiGagal(
                $alasan . ' | Pesan ini hanya bisa lewat WAHA (tidak ada template resminya), '
                . 'jadi tidak bisa dialihkan ke jalur berbayar. Kirim manual dari layar chat.'
            );

            Log::warning('[CRM] notifikasi khusus WAHA menyerah setelah tertahan', [
                'outbox_id' => $baris->id, 'alasan' => $alasan,
            ]);

            return 'gagal';
        }

        // Dicatat DULU, supaya penahanan pertama punya jam mulai sebelum
        // batasnya diperiksa pada jalan berikutnya.
        $baris->tandaiTertahan($alasan, now()->addMinutes((int) config('crm.notifikasi.tahan_jeda_menit', 10)));

        if ($batas <= 0 || ! $baris->tertahanLebihDari($batas)) {
            Log::info('[CRM] notifikasi ditahan, antrean menunggu', ['outbox_id' => $baris->id, 'alasan' => $alasan]);

            return 'tertahan';
        }

        $resmi = $this->notifikasi->resmi();

        $hasil = $resmi->kirimNotifikasi([
            'to'        => $baris->recipient,
            'template'  => $baris->template_name,
            'language'  => 'id',
            'body'      => (array) $baris->template_body,
            'url_lacak' => $this->urlLacak($baris),
        ]);

        if (! ($hasil['success'] ?? false)) {
            /*
             * Kedua jalur sedang mati sekaligus. Tetap ditahan, bukan
             * digagalkan: yang jatuh adalah jalurnya, bukan pesannya, dan
             * begitu salah satu pulih pesan ini masih berhak berangkat.
             */
            $baris->tandaiTertahan(
                $alasan . ' | Eskalasi ke template resmi juga gagal: ' . ($hasil['error'] ?? 'tanpa keterangan'),
                now()->addMinutes((int) config('crm.notifikasi.tahan_jeda_menit', 10))
            );

            Log::warning('[CRM] eskalasi notifikasi gagal, kedua jalur mati', [
                'outbox_id' => $baris->id,
                'waha'      => $alasan,
                'resmi'     => $hasil['error'] ?? null,
            ]);

            return 'tertahan';
        }

        $baris->tandaiTerkirim(
            $hasil['message_id'] ?? null,
            "Dialihkan ke template resmi setelah tertahan lebih dari {$batas} jam ({$alasan})"
        );

        Log::info('[CRM] notifikasi dieskalasi ke jalur resmi', ['outbox_id' => $baris->id, 'alasan' => $alasan]);

        return 'terkirim';
    }

    /**
     * Halaman lacak milik pembeli. Token dibuat di sini bila belum ada — sah
     * karena pesanan marketplace sudah tersaring jauh sebelum titik ini.
     *
     * Yang diserahkan URL PENUH: jalur WAHA menempelkannya sebagai teks,
     * sedangkan jalur resmi memotong bagian akhirnya untuk tombol URL dinamis.
     * Null → tombol dikirim tanpa parameter; template tetap sah, tombolnya saja
     * yang mengarah ke halaman umum.
     */
    private function urlLacak(CrmOutboxMessage $baris): ?string
    {
        $so = $baris->sales_order_id ? SalesOrder::find($baris->sales_order_id) : null;

        if (! $so) {
            return null;
        }

        $so->ensurePublicToken();

        return $so->publicTrackUrl();
    }
}
