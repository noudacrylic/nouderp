<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim antrean notifikasi. Dipanggil terjadwal (crm:kirim-notifikasi) dan
 * kelak oleh tombol "Kirim Sekarang" di layar pesanan.
 *
 * Selama saklar jangan-kirim menyala, ChatManager menyerahkan driver palsu —
 * baris tetap ditandai terkirim dan alurnya teruji penuh, tapi tak sebutir pun
 * paket keluar. Itu memang yang diinginkan sampai Tahap 6.
 */
class CrmOutboxSender
{
    public function __construct(private ChatManager $chat)
    {
    }

    /** Kirim semua yang jatuh tempo. @return array{terkirim:int, gagal:int} */
    public function kirimYangJatuhTempo(int $limit = 50): array
    {
        $terkirim = 0;
        $gagal    = 0;

        $antrean = CrmOutboxMessage::jatuhTempo()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($antrean as $baris) {
            $this->kirimSatu($baris) ? $terkirim++ : $gagal++;
        }

        return ['terkirim' => $terkirim, 'gagal' => $gagal];
    }

    public function kirimSatu(CrmOutboxMessage $baris): bool
    {
        if (blank($baris->recipient) || blank($baris->template_name)) {
            $baris->tandaiGagal('Baris outbox tidak lengkap (nomor atau nama template kosong).');

            return false;
        }

        $hasil = $this->chat->provider()->sendTemplate([
            'to'                => $baris->recipient,
            'template'          => $baris->template_name,
            'language'          => 'id',
            'body'              => (array) $baris->template_body,
            'url_button_suffix' => $this->tokenLacak($baris),
        ]);

        if (! ($hasil['success'] ?? false)) {
            $baris->tandaiGagal((string) ($hasil['error'] ?? 'Gagal tanpa keterangan.'));
            Log::warning('[CRM] notifikasi gagal terkirim', ['outbox_id' => $baris->id, 'error' => $hasil['error'] ?? null]);

            return false;
        }

        $baris->tandaiTerkirim($hasil['message_id'] ?? null);

        return true;
    }

    /**
     * Potongan akhir URL tombol "Lihat Pesanan" = token halaman lacak milik
     * pembeli. Token dibuat di sini bila belum ada — sah karena pesanan
     * marketplace sudah tersaring jauh sebelum titik ini.
     *
     * Null → tombol dikirim tanpa parameter; template tetap sah, tombolnya saja
     * yang mengarah ke halaman umum.
     */
    private function tokenLacak(CrmOutboxMessage $baris): ?string
    {
        $so = $baris->sales_order_id ? SalesOrder::find($baris->sales_order_id) : null;

        return $so?->ensurePublicToken();
    }
}
