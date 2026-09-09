<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Support\MediaKind;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\UploadedFile;

/**
 * Mengirim balasan admin dari layar ERP — teks maupun gambar.
 *
 * PENJAGA JENDELA 24 JAM ADA DI SINI, bukan cuma di tampilan. Kalau hanya
 * tombolnya yang disembunyikan, admin yang membuka thread lama tetap akan
 * mengetik panjang lebar, ditolak API, lalu kembali membalas dari HP — dan
 * seluruh sistem triase mati sebelum sempat dipakai. Ditolak di sini berarti
 * ditolak sebelum satu huruf pun dikirim, dengan alasan yang bisa dibaca.
 *
 * Selama saklar crm.dry_run menyala, ChatManager menyerahkan driver palsu:
 * pesannya tetap tercatat lengkap di thread, tapi tak ada yang keluar — dan
 * baris itu ditandai `tidak_dikirim`, BUKAN `terkirim`. Bedanya menentukan:
 * gelembung yang mengaku terkirim membuat admin mengira pelanggan sudah
 * dijawab, lalu menunggu balasan yang tak akan pernah datang.
 */
class CrmReplyService
{
    public function __construct(
        private ChatManager $chat,
        private CrmMediaStore $media,
    ) {
    }

    /**
     * @param  UploadedFile[]  $berkas  gambar yang ditempel/dipilih admin
     * @return array{success:bool, message:?CrmMessage, error:?string}
     */
    public function balas(
        CrmConversation $percakapan,
        string $teks,
        ?int $userId = null,
        ?string $replyToWamId = null,
        array $berkas = []
    ): array {
        $teks   = trim($teks);
        $berkas = array_values(array_filter($berkas));

        if ($teks === '' && ! $berkas) {
            return $this->gagal('Pesan kosong.');
        }

        if (! $percakapan->windowIsOpen()) {
            return $this->gagal(
                'Jendela 24 jam sudah tertutup — pesan bebas tidak bisa dikirim. '
                . 'Pakai template penyusul, atau tunggu pelanggan membalas lebih dulu.'
            );
        }

        return $berkas
            ? $this->kirimGambar($percakapan, $teks, $berkas, $userId, $replyToWamId)
            : $this->kirimTeks($percakapan, $teks, $userId, $replyToWamId);
    }

    /**
     * MULAI percakapan baru ke nomor yang belum pernah menghubungi kita.
     *
     * Wajib lewat template, dan itu aturan Meta soal JENDELA 24 JAM — bukan
     * soal siapa yang mengetik. Pesan bebas ke nomor dingin selalu ditolak,
     * entah disusun manusia atau robot. Karena itu tak ada jalur teks di sini
     * sama sekali: menyediakannya berarti menjanjikan sesuatu yang pasti gagal.
     *
     * Jendela TIDAK diperiksa — justru inilah satu-satunya cara sah membukanya.
     *
     * @param  string[]  $variabel  nilai {{1}}, {{2}}, … berurutan
     * @return array{success:bool, conversation:?CrmConversation, error:?string}
     */
    public function mulaiPercakapan(
        string $nomor,
        string $template,
        array $variabel = [],
        ?int $userId = null,
        ?string $bunyiTemplate = null,
        ?string $bahasa = 'id'
    ): array {
        $tujuan = PhoneNumber::normalize($nomor);

        if (! $tujuan) {
            return ['success' => false, 'conversation' => null, 'error' => 'Nomor tujuan tidak valid.'];
        }

        if (trim($template) === '') {
            return ['success' => false, 'conversation' => null, 'error' => 'Template belum dipilih.'];
        }

        $variabel = array_values(array_map(fn ($v) => (string) $v, $variabel));

        $hasil = $this->chat->provider()->sendTemplate([
            'to'       => $tujuan,
            'template' => $template,
            'language' => $bahasa ?: 'id',
            'body'     => $variabel,
        ]);

        if (! ($hasil['success'] ?? false)) {
            return ['success' => false, 'conversation' => null, 'error' => (string) ($hasil['error'] ?? 'Gagal mengirim template.')];
        }

        $percakapan = CrmConversation::findOrCreateFor($tujuan);

        $pesan = $this->catat(
            $percakapan,
            'template',
            // Bunyi template disusun ulang di sini supaya thread bisa DIBACA.
            // Menyimpan sekadar nama template membuat riwayatnya tak berarti
            // bagi siapa pun yang membukanya bulan depan.
            $this->susunBunyi($bunyiTemplate, $variabel) ?: $template,
            $hasil,
            $userId,
            null
        );

        $pesan->forceFill(['raw' => ['template' => $template, 'variables' => $variabel] + (array) $pesan->raw])->save();

        /*
         * Bola ada di PELANGGAN: kita sudah menyapa, sekarang menunggu ia
         * membalas — dan balasannya itulah yang membuka jendela 24 jam.
         */
        $this->geserBola($percakapan);

        return ['success' => true, 'conversation' => $percakapan, 'error' => null];
    }

    /** Ganti {{1}}, {{2}}, … dengan nilainya untuk ditampilkan di thread. */
    private function susunBunyi(?string $bunyi, array $variabel): ?string
    {
        if (! $bunyi) {
            return null;
        }

        foreach ($variabel as $i => $nilai) {
            $bunyi = str_replace('{{' . ($i + 1) . '}}', $nilai, $bunyi);
        }

        return $bunyi;
    }

    /* ------------------------------------------------------------------ teks */

    private function kirimTeks(CrmConversation $percakapan, string $teks, ?int $userId, ?string $replyTo): array
    {
        $hasil = $this->chat->provider()->sendText([
            'to'              => $percakapan->contact_key,
            'text'            => $teks,
            'reply_to'        => $replyTo,
            'channel'         => $percakapan->channel,
            'phone_number_id' => $percakapan->business_number_id,
        ]);

        if (! ($hasil['success'] ?? false)) {
            return $this->gagal((string) ($hasil['error'] ?? 'Gagal mengirim tanpa keterangan.'));
        }

        $pesan = $this->catat($percakapan, 'text', $teks, $hasil, $userId, $replyTo);

        $this->geserBola($percakapan);

        return ['success' => true, 'message' => $pesan, 'error' => null];
    }

    /* ---------------------------------------------------------------- gambar */

    /**
     * Satu gambar = satu pesan (WhatsApp tidak mengenal satu pesan berisi banyak
     * media). Teksnya menempel sebagai caption pada gambar PERTAMA saja; kalau
     * diulang di tiap gambar, pelanggan menerima kalimat yang sama berkali-kali.
     *
     * Berkasnya diunggah ke vendor untuk mendapat `media_id`, TAPI tetap
     * disimpan di disk kita sendiri: media_id itu kedaluwarsa 30 hari, sedangkan
     * revisi desain justru yang paling sering dibuka lagi berbulan kemudian.
     *
     * @param  UploadedFile[]  $berkas
     */
    private function kirimGambar(
        CrmConversation $percakapan,
        string $teks,
        array $berkas,
        ?int $userId,
        ?string $replyTo
    ): array {
        $provider = $this->chat->provider();
        $terakhir = null;

        foreach ($berkas as $i => $file) {
            $caption = $i === 0 ? ($teks ?: null) : null;
            $jenis   = MediaKind::for($file->getClientMimeType());

            /*
             * Baris pesan & lampirannya dibuat LEBIH DULU, sebelum dikirim.
             * Terbalik dari jalur teks, dan memang harus: vendor menuntut
             * `media_url` berupa URL yang bisa diambil Meta, dan URL itu baru
             * bisa dirangkai setelah lampirannya punya id dan berkasnya duduk
             * di disk. Kalau pengiriman gagal, keduanya dihapus lagi — thread
             * tak boleh menyimpan pesan yang tak pernah sampai.
             */
            $pesan    = $this->catat($percakapan, $jenis['type'], $caption, [], $userId, $i === 0 ? $replyTo : null);
            $lampiran = $this->media->simpanUnggahan($pesan, $file);

            $hasil = $provider->sendMedia([
                'to'              => $percakapan->contact_key,
                'type'            => $jenis['type'],
                'media'           => $this->tautanSementara($lampiran),
                'caption'         => $caption,
                'nama_berkas'     => $file->getClientOriginalName(),
                'channel'         => $percakapan->channel,
                'phone_number_id' => $percakapan->business_number_id,
            ]);

            if (! ($hasil['success'] ?? false)) {
                $lampiran->delete();
                $pesan->delete();

                return $this->gagal(
                    'Gagal mengirim ' . $file->getClientOriginalName() . ': ' . ($hasil['error'] ?? 'tanpa keterangan')
                    . ($terakhir ? ' (lampiran sebelumnya sudah terkirim)' : '')
                );
            }

            $pesan->forceFill([
                'provider_message_id' => $hasil['message_id'] ?? null,
                'raw'                 => $hasil['raw'] ?? [],
            ])->save();

            $terakhir = $pesan;
        }

        $this->geserBola($percakapan);

        return ['success' => true, 'message' => $terakhir, 'error' => null];
    }

    /**
     * Kirim SATU gambar yang alamatnya sudah kita punya (foto etalase di R2)
     * berikut captionnya.
     *
     * Ada karena WhatsApp TIDAK selalu memunculkan pratinjau otomatis untuk
     * tautan yang dikirim — kalau pengambil pratinjau Meta gagal membuka
     * halaman etalase, yang sampai ke pembeli cuma sebaris URL telanjang.
     * Mengirim fotonya sendiri membuat pratinjaunya jadi urusan kita, bukan
     * urusan pengambil halaman milik orang lain.
     *
     * @return array{success:bool, message:?CrmMessage, error:?string}
     */
    public function kirimFoto(
        CrmConversation $percakapan,
        string $urlGambar,
        string $caption,
        ?int $userId = null
    ): array {
        if (! $percakapan->windowIsOpen()) {
            return $this->gagal(
                'Jendela 24 jam sudah tertutup — foto tidak bisa dikirim. '
                . 'Pakai template penyusul, atau tunggu pelanggan membalas lebih dulu.'
            );
        }

        $caption = trim($caption);
        $pesan   = $this->catat($percakapan, 'image', $caption ?: null, [], $userId, null);

        $lampiran = $this->media->simpanDariUrl($pesan, $urlGambar);

        if (! $lampiran) {
            $pesan->delete();

            return $this->gagal('Foto produk tidak bisa diambil dari etalase. Kirim tautannya saja, atau tempel fotonya manual.');
        }

        $hasil = $this->chat->provider()->sendMedia([
            'to'              => $percakapan->contact_key,
            'type'            => 'image',
            'media'           => $this->tautanSementara($lampiran),
            'caption'         => $caption ?: null,
            'nama_berkas'     => $lampiran->original_name,
            'channel'         => $percakapan->channel,
            'phone_number_id' => $percakapan->business_number_id,
        ]);

        if (! ($hasil['success'] ?? false)) {
            $lampiran->delete();
            $pesan->delete();

            return $this->gagal('Gagal mengirim foto produk: ' . ($hasil['error'] ?? 'tanpa keterangan'));
        }

        $pesan->forceFill([
            'provider_message_id' => $hasil['message_id'] ?? null,
            'raw'                 => $hasil['raw'] ?? [],
        ])->save();

        $this->geserBola($percakapan);

        return ['success' => true, 'message' => $pesan, 'error' => null];
    }

    /**
     * URL sementara bertanda tangan untuk satu lampiran keluar.
     *
     * Meta harus bisa MENGAMBIL berkasnya sendiri, jadi tak ada jalan lain
     * selain memberi alamat yang terbuka. Yang menjaganya: tanda tangan (tak
     * bisa ditebak maupun diubah), umur pendek, dan rute yang hanya mau
     * menyajikan lampiran pesan KELUAR — berkas kiriman pelanggan tetap
     * tertutup rapat.
     *
     * ⚠️ Dirangkai dari host permintaan yang sedang berjalan, bukan APP_URL:
     * di lokal APP_URL menunjuk 127.0.0.1 yang tak bisa dijangkau Meta,
     * sedangkan host permintaan adalah alamat ngrok yang benar.
     */
    private function tautanSementara(CrmAttachment $lampiran): string
    {
        return URL::temporarySignedRoute(
            'crm.media',
            now()->addMinutes((int) config('crm.media_link_minutes', 30)),
            ['attachment' => $lampiran->id]
        );
    }

    /* -------------------------------------------------------------- teruskan */

    /**
     * Teruskan satu pesan ke percakapan lain.
     *
     * BUKAN "forward" WhatsApp yang sesungguhnya: Cloud API tidak punya
     * endpoint teruskan, dan label "Diteruskan" yang biasa muncul di HP tidak
     * bisa disetel dari luar. Yang terjadi di sini adalah isinya DIKIRIM ULANG
     * sebagai pesan baru — di HP penerima ia tampak seperti pesan biasa. Jejak
     * asalnya hanya hidup di ERP, lewat `forwarded_from_message_id`.
     *
     * Kutipan sengaja TIDAK ikut diteruskan. Pesan yang dikutip milik
     * percakapan lain; membawanya serta berarti memperlihatkan potongan chat
     * orang lain kepada penerima yang tidak ada hubungannya.
     *
     * @return array{success:bool, message:?CrmMessage, error:?string}
     */
    public function teruskan(CrmMessage $sumber, CrmConversation $tujuan, ?int $userId = null): array
    {
        if (! $tujuan->windowIsOpen()) {
            return $this->gagal(
                'Jendela 24 jam ' . $tujuan->namaTampil() . ' sudah tertutup — pesan tidak bisa diteruskan ke sana. '
                . 'Pakai template penyusul, atau tunggu ia membalas lebih dulu.'
            );
        }

        $sumber->loadMissing('attachments');

        $teks     = trim((string) $sumber->content);
        $lampiran = $sumber->attachments->filter(fn (CrmAttachment $l) => $l->tersimpanAman())->values();

        if ($teks === '' && $lampiran->isEmpty()) {
            /*
             * Lampiran yang belum/gagal terunduh sengaja dibedakan dari pesan
             * yang memang kosong. Yang pertama bisa diperbaiki (tombol unduh
             * ulang ada di thread); yang kedua tidak ada yang bisa dikerjakan.
             */
            return $this->gagal($sumber->attachments->isNotEmpty()
                ? 'Lampirannya belum tersimpan di ERP, jadi belum bisa diteruskan. Unduh dulu lampirannya, lalu ulangi.'
                : 'Pesan ini tidak punya isi yang bisa diteruskan.');
        }

        return $lampiran->isNotEmpty()
            ? $this->teruskanLampiran($sumber, $tujuan, $teks, $lampiran->all(), $userId)
            : $this->teruskanTeks($sumber, $tujuan, $teks, $userId);
    }

    private function teruskanTeks(CrmMessage $sumber, CrmConversation $tujuan, string $teks, ?int $userId): array
    {
        $hasil = $this->chat->provider()->sendText([
            'to'              => $tujuan->contact_key,
            'text'            => $teks,
            'channel'         => $tujuan->channel,
            'phone_number_id' => $tujuan->business_number_id,
        ]);

        if (! ($hasil['success'] ?? false)) {
            return $this->gagal((string) ($hasil['error'] ?? 'Gagal meneruskan tanpa keterangan.'));
        }

        $pesan = $this->catat($tujuan, 'text', $teks, $hasil, $userId, null, $sumber->id);

        $this->geserBola($tujuan);

        return ['success' => true, 'message' => $pesan, 'error' => null];
    }

    /**
     * @param  CrmAttachment[]  $lampiran
     */
    private function teruskanLampiran(
        CrmMessage $sumber,
        CrmConversation $tujuan,
        string $teks,
        array $lampiran,
        ?int $userId
    ): array {
        $provider = $this->chat->provider();
        $terakhir = null;

        foreach ($lampiran as $i => $asal) {
            // Teks menempel sebagai caption pada berkas PERTAMA saja — sama
            // seperti jalur kirim gambar biasa; diulang tiap berkas berarti
            // penerima membaca kalimat yang sama berkali-kali.
            $caption = $i === 0 ? ($teks ?: null) : null;
            $jenis   = MediaKind::for((string) $asal->mime);

            $pesan   = $this->catat($tujuan, $jenis['type'], $caption, [], $userId, null, $sumber->id);
            $salinan = $this->media->salin($pesan, $asal);

            if (! $salinan) {
                $pesan->delete();

                return $this->gagal('Berkas "' . ($asal->original_name ?: 'lampiran') . '" tidak ada lagi di penyimpanan ERP.');
            }

            $hasil = $provider->sendMedia([
                'to'              => $tujuan->contact_key,
                'type'            => $jenis['type'],
                'media'           => $this->tautanSementara($salinan),
                'caption'         => $caption,
                'nama_berkas'     => $asal->original_name,
                'channel'         => $tujuan->channel,
                'phone_number_id' => $tujuan->business_number_id,
            ]);

            if (! ($hasil['success'] ?? false)) {
                $salinan->delete();
                $pesan->delete();

                return $this->gagal(
                    'Gagal meneruskan ' . ($asal->original_name ?: 'lampiran') . ': ' . ($hasil['error'] ?? 'tanpa keterangan')
                    . ($terakhir ? ' (lampiran sebelumnya sudah terkirim)' : '')
                );
            }

            $pesan->forceFill([
                'provider_message_id' => $hasil['message_id'] ?? null,
                'raw'                 => $hasil['raw'] ?? [],
            ])->save();

            $terakhir = $pesan;
        }

        $this->geserBola($tujuan);

        return ['success' => true, 'message' => $terakhir, 'error' => null];
    }

    /* ---------------------------------------------------------------- bantuan */

    private function catat(
        CrmConversation $percakapan,
        string $tipe,
        ?string $isi,
        array $hasil,
        ?int $userId,
        ?string $replyTo,
        ?int $diteruskanDari = null
    ): CrmMessage {
        return CrmMessage::create([
            'conversation_id'     => $percakapan->id,
            'direction'           => CrmMessage::KELUAR,
            'message_type'        => $tipe,
            'content'             => $isi,
            'source'              => CrmMessage::SOURCE_ERP,
            'provider_message_id' => $hasil['message_id'] ?? null,
            'reply_to_wam_id'     => $replyTo,
            'forwarded_from_message_id' => $diteruskanDari,
            'status'              => $this->chat->isDryRun()
                ? CrmMessage::STATUS_TIDAK_DIKIRIM
                : 'terkirim',
            'sent_by_user_id'     => $userId,
            'sent_at'             => now(),
            'raw'                 => $hasil['raw'] ?? [],
        ]);
    }

    /**
     * Bola pindah ke pelanggan begitu kita menjawab. Tanpa ini, percakapan
     * yang sudah dibalas tetap menumpuk di "Menunggu Kita" dan antreannya
     * berhenti dipercaya — itu satu-satunya hal yang membuat layar ini
     * berguna dibanding membuka WhatsApp di HP.
     */
    private function geserBola(CrmConversation $percakapan): void
    {
        $percakapan->forceFill([
            'last_outbound_at' => now(),
            'last_message_at'  => now(),
            'unread_count'     => 0,
            'queue_state'      => CrmConversation::QUEUE_PELANGGAN,
        ])->save();
    }

    private function gagal(string $pesan): array
    {
        return ['success' => false, 'message' => null, 'error' => $pesan];
    }
}
