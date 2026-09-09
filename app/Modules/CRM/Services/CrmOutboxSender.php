<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\NotificationManager;
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
    public function __construct(private NotificationManager $notifikasi)
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

        $hasil = $this->notifikasi->provider()->kirimNotifikasi([
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
