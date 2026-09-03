<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;

/**
 * Mengirim balasan admin dari layar ERP.
 *
 * PENJAGA JENDELA 24 JAM ADA DI SINI, bukan cuma di tampilan. Kalau hanya
 * tombolnya yang disembunyikan, admin yang membuka thread lama tetap akan
 * mengetik panjang lebar, ditolak API, lalu kembali membalas dari HP — dan
 * seluruh sistem triase mati sebelum sempat dipakai. Ditolak di sini berarti
 * ditolak sebelum satu huruf pun dikirim, dengan alasan yang bisa dibaca.
 *
 * Selama saklar crm.dry_run menyala, ChatManager menyerahkan driver palsu:
 * pesannya tetap tercatat lengkap di thread, tapi tak ada yang keluar.
 */
class CrmReplyService
{
    public function __construct(private ChatManager $chat)
    {
    }

    /**
     * @return array{success:bool, message:?CrmMessage, error:?string}
     */
    public function balas(CrmConversation $percakapan, string $teks, ?int $userId = null, ?string $replyToWamId = null): array
    {
        $teks = trim($teks);

        if ($teks === '') {
            return $this->gagal('Pesan kosong.');
        }

        if (! $percakapan->windowIsOpen()) {
            return $this->gagal(
                'Jendela 24 jam sudah tertutup — pesan bebas tidak bisa dikirim. '
                . 'Pakai template penyusul, atau tunggu pelanggan membalas lebih dulu.'
            );
        }

        $hasil = $this->chat->provider()->sendText([
            'to'              => $percakapan->contact_key,
            'text'            => $teks,
            'reply_to'        => $replyToWamId,
            'channel'         => $percakapan->channel,
            'phone_number_id' => $percakapan->business_number_id,
        ]);

        if (! ($hasil['success'] ?? false)) {
            return $this->gagal((string) ($hasil['error'] ?? 'Gagal mengirim tanpa keterangan.'));
        }

        $pesan = CrmMessage::create([
            'conversation_id'     => $percakapan->id,
            'direction'           => CrmMessage::KELUAR,
            'message_type'        => 'text',
            'content'             => $teks,
            'source'              => CrmMessage::SOURCE_ERP,
            'provider_message_id' => $hasil['message_id'] ?? null,
            'reply_to_wam_id'     => $replyToWamId,
            'status'              => 'terkirim',
            'sent_by_user_id'     => $userId,
            'sent_at'             => now(),
            'raw'                 => $hasil['raw'] ?? [],
        ]);

        /*
         * Bola pindah ke pelanggan begitu kita menjawab. Tanpa ini, percakapan
         * yang sudah dibalas tetap menumpuk di "Menunggu Kita" dan antreannya
         * berhenti dipercaya — itu satu-satunya hal yang membuat layar ini
         * berguna dibanding membuka WhatsApp di HP.
         */
        $percakapan->forceFill([
            'last_outbound_at' => now(),
            'last_message_at'  => now(),
            'unread_count'     => 0,
            'queue_state'      => CrmConversation::QUEUE_PELANGGAN,
        ])->save();

        return ['success' => true, 'message' => $pesan, 'error' => null];
    }

    private function gagal(string $pesan): array
    {
        return ['success' => false, 'message' => null, 'error' => $pesan];
    }
}
