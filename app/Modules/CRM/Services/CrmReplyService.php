<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Support\MediaKind;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\CRM\Support\TemplateResmi;
use Carbon\Carbon;
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

        if ($tolak = $this->pastikanJendela(
            $percakapan,
            'Jendela 24 jam sudah tertutup — pesan bebas tidak bisa dikirim. '
            . 'Pakai template penyusul, atau tunggu pelanggan membalas lebih dulu.'
        )) {
            return $tolak;
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

        $this->catatTemplate($percakapan, $template, $variabel, $bunyiTemplate, $hasil, $userId);

        /*
         * Bola ada di PELANGGAN: kita sudah menyapa, sekarang menunggu ia
         * membalas — dan balasannya itulah yang membuka jendela 24 jam.
         */
        $this->geserBola($percakapan);

        return ['success' => true, 'conversation' => $percakapan, 'error' => null];
    }

    /**
     * Ambang (menit) sisa jendela yang membuat pancingan pantas dikirim.
     *
     * Satu jam. Cukup lama untuk sempat dilihat & ditekan sebelum jendelanya
     * habis, cukup dekat untuk tidak mengganggu percakapan yang sebenarnya
     * masih berjalan — kalau pelanggan membalas sesudah ini, jendelanya
     * bergeser dan pancingan berikutnya lahir dari hitungan yang baru.
     */
    public const AMBANG_PANCINGAN_MENIT = 60;

    /**
     * PANCINGAN: minta pelanggan menekan tombol supaya jendelanya diperbarui.
     *
     * Lahir dari keadaan yang tidak jarang sama sekali — diskusi desain yang
     * belum kelar lalu bertemu hari libur. Jendela 24 jam habis di tengah
     * pembahasan, dan Senin pagi kita tak bisa menyambung satu kalimat pun
     * sampai pelanggan kebetulan menyapa duluan.
     *
     * DUA JALUR, dan yang memilih adalah keadaan jendelanya:
     *
     *  1. MASIH TERBUKA (jalur biasa, dan yang dituju penjadwal): pesan sesi
     *     bertombol. GRATIS, tanpa peninjauan Meta. Inilah alasan pancingan
     *     dikirim sejam SEBELUM habis, bukan sesudah — sejam lebih awal
     *     harganya nol, semenit terlambat harganya tarif template.
     *
     *  2. SUDAH TERTUTUP (jaring pengaman, dipanggil tangan dari layar chat):
     *     template 'lanjut_diskusi'. BERBAYAR. Dipakai kalau pancingan gratis
     *     terlewat — penjadwal mati, chat baru diperhatikan Senin siang — dan
     *     pelanggan tidak menyapa duluan.
     *
     * Tombolnyalah yang bekerja, bukan kalimatnya. Sekali ditekan, pelanggan
     * mengirim pesan masuk sungguhan — dan pesan masuk itu yang memperbarui
     * jendela, tanpa ia perlu memikirkan kalimat apa pun. Meminta "mohon balas
     * ya" memindahkan beban itu kepada orang yang sedang tidak memikirkan kita.
     *
     * @param  bool  $waktunyaSudahDiputuskan  penjadwal sudah menimbang sendiri
     *         kapan pancingan ini pantas berangkat, jadi ambang sejam di bawah
     *         tidak berlaku. Dipakai untuk satu keadaan saja, dan keadaan itu
     *         nyata: jendela yang habis pukul tiga pagi harus dipancing malam
     *         sebelumnya, jauh lebih awal dari sejam. Yang menekan tombol
     *         di layar TIDAK PERNAH mengirimkan ini — di sanalah ambangnya
     *         justru berguna.
     * @return array{success:bool, message:?CrmMessage, error:?string}
     */
    public function kirimPancingan(
        CrmConversation $percakapan,
        ?int $userId = null,
        bool $waktunyaSudahDiputuskan = false
    ): array {
        /*
         * Jendela yang masih lapang DITOLAK, dan itu bukan kehati-hatian
         * berlebihan: pancingan yang datang saat percakapan masih hangat
         * terbaca seperti diusir. Ia baru masuk akal di ujung jendela.
         */
        if (! $waktunyaSudahDiputuskan
            && $percakapan->windowIsOpen()
            && ! $percakapan->windowHampirTutup(self::AMBANG_PANCINGAN_MENIT)) {
            return $this->gagal(
                'Jendela masih terbuka ' . $percakapan->windowHoursLeft() . ' jam lagi — '
                . 'balas biasa saja. Pancingan baru berguna saat jendelanya tinggal sekitar sejam atau sudah tutup.'
            );
        }

        $hasil = $percakapan->windowIsOpen()
            ? $this->pancinganSesi($percakapan, $userId)
            : $this->pancinganTemplate($percakapan, $userId);

        /*
         * Penanda dipasang hanya kalau pesannya benar-benar berangkat.
         * Dipasang di depan, kegagalan sesaat (vendor 500) akan mengunci
         * percakapan itu dari pancingan sampai jendelanya berganti — dan
         * jendelanya tak akan berganti, karena justru itu yang sedang
         * diusahakan.
         */
        if ($hasil['success']) {
            $percakapan->forceFill(['pancingan_untuk_jendela_at' => $percakapan->window_expires_at])->save();
        }

        return $hasil;
    }

    /** Jalur GRATIS: pesan sesi bertombol, selagi jendela masih terbuka. */
    private function pancinganSesi(CrmConversation $percakapan, ?int $userId): array
    {
        $teks = TemplateResmi::bunyiPancinganSesi(
            $percakapan->sapaan(),
            (string) config('crm.store_hours_text', 'jam kerja')
        );

        $hasil = $this->chat->provider()->sendInteraktif([
            'to'              => $percakapan->contact_key,
            'text'            => $teks,
            'buttons'         => [['id' => 'lanjut_diskusi', 'title' => TemplateResmi::TOMBOL_PANCINGAN]],
            'channel'         => $percakapan->channel,
            'phone_number_id' => $percakapan->business_number_id,
        ]);

        if (! ($hasil['success'] ?? false)) {
            return $this->gagal('Gagal mengirim pancingan: ' . ($hasil['error'] ?? 'tanpa keterangan'));
        }

        $pesan = $this->catat($percakapan, 'interactive', $teks, $hasil, $userId, null);

        /*
         * Tombolnya ikut dicatat. Yang dikembalikan vendor cuma jawaban atas
         * kiriman, bukan salinan kirimannya — tanpa baris ini gelembung di ERP
         * tak punya cara tahu pesan ini bertombol, dan kalimat "silakan tekan
         * tombol di bawah ini" tampil menggantung tanpa tombol.
         */
        $pesan->forceFill([
            'raw' => ['tombol' => [TemplateResmi::TOMBOL_PANCINGAN]] + (array) $pesan->raw,
        ])->save();

        $this->geserBola($percakapan);

        return ['success' => true, 'message' => $pesan, 'error' => null];
    }

    /** Jalur BERBAYAR: template Meta, satu-satunya yang sah setelah jendela tutup. */
    private function pancinganTemplate(CrmConversation $percakapan, ?int $userId): array
    {
        $nama     = TemplateResmi::TEMPLATE_PANCINGAN;
        $variabel = [$percakapan->sapaan(), (string) config('crm.store_hours_text', 'jam kerja')];

        $hasil = $this->chat->provider()->sendTemplate([
            'to'       => $percakapan->contact_key,
            'template' => $nama,
            'language' => 'id',
            'body'     => $variabel,
        ]);

        if (! ($hasil['success'] ?? false)) {
            return $this->gagal('Gagal mengirim pancingan berbayar: ' . ($hasil['error'] ?? 'tanpa keterangan'));
        }

        $pesan = $this->catatTemplate($percakapan, $nama, $variabel, TemplateResmi::body($nama), $hasil, $userId);

        $this->geserBola($percakapan);

        return ['success' => true, 'message' => $pesan, 'error' => null];
    }

    /**
     * Catat satu template terkirim ke thread.
     *
     * Yang disimpan sebagai isi pesan adalah BUNYINYA yang sudah terisi, bukan
     * nama templatenya. Nama template membuat riwayat tak berarti bagi siapa
     * pun yang membukanya bulan depan — sedangkan nama & variabel aslinya tetap
     * ikut, di `raw`, untuk yang memang sedang menelusuri.
     */
    private function catatTemplate(
        CrmConversation $percakapan,
        string $template,
        array $variabel,
        ?string $bunyiTemplate,
        array $hasil,
        ?int $userId
    ): CrmMessage {
        $pesan = $this->catat(
            $percakapan,
            'template',
            $this->susunBunyi($bunyiTemplate, $variabel) ?: $template,
            $hasil,
            $userId,
            null
        );

        $pesan->forceFill(['raw' => ['template' => $template, 'variables' => $variabel] + (array) $pesan->raw])->save();

        return $pesan;
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
        if ($tolak = $this->pastikanJendela(
            $percakapan,
            'Jendela 24 jam sudah tertutup — foto tidak bisa dikirim. '
            . 'Pakai template penyusul, atau tunggu pelanggan membalas lebih dulu.'
        )) {
            return $tolak;
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
        if ($tolak = $this->pastikanJendela(
            $tujuan,
            'Jendela 24 jam ' . $tujuan->namaTampil() . ' sudah tertutup — pesan tidak bisa diteruskan ke sana. '
            . 'Pakai template penyusul, atau tunggu ia membalas lebih dulu.'
        )) {
            return $tolak;
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

    /* ---------------------------------------------------- penjaga jendela 24 jam */

    /**
     * Ambang (menit) sisa jendela yang membuat kita berhenti percaya catatan
     * sendiri dan bertanya ke vendor.
     *
     * Satu jam, bukan lebih: yang sedang dijaga adalah SELISIH antara jam kita
     * dan jam Meta, dan selisih itu lahir dari webhook yang terlambat sampai —
     * hitungan menit, sesekali belasan menit. Ambang yang lebih lebar hanya
     * menambah panggilan API tanpa menangkap kekeliruan tambahan.
     */
    private const AMBANG_TANYA_MENIT = 60;

    /**
     * Pastikan pesan bebas memang boleh keluar, sebelum satu huruf pun dikirim.
     *
     * Catatan lokal `window_expires_at` dihitung dari timestamp webhook, dan itu
     * hampir selalu cukup. Yang tidak cukup: webhook yang datang terlambat.
     * Kalau pesan pelanggan pukul 09.00 baru sampai ke ERP pukul 09.20 tanpa
     * membawa timestamp, jendela versi kita berakhir 20 menit LEBIH LAMBAT dari
     * versi Meta — dan di dua puluh menit itu layar dengan yakin menampilkan
     * kotak ketik untuk pesan yang pasti ditolak.
     *
     * Karena itu vendor hanya ditanya di ujung jendela. Menanyakannya tiap kali
     * berarti satu panggilan API untuk setiap balasan; menanyakannya di daftar
     * percakapan berarti satu panggilan per baris. Keduanya membayar mahal untuk
     * kepastian yang, di tengah jendela, tidak pernah dibutuhkan.
     *
     * Vendor yang TIDAK BISA DIHUBUNGI sengaja tidak menghalangi: jaringan
     * bermasalah bukan alasan menolak balasan yang menurut catatan kita sah.
     * Kalau ternyata Meta menolaknya, penolakan itu tetap sampai ke admin lewat
     * jalur kirim biasa — sedangkan menutup layar karena vendor sedang lambat
     * mengunci admin dari pekerjaan yang sebenarnya boleh ia lakukan.
     *
     * @return array{success:bool, message:null, error:string}|null  null = boleh lanjut
     */
    private function pastikanJendela(CrmConversation $percakapan, string $tolakan): ?array
    {
        if ($percakapan->windowHampirTutup(self::AMBANG_TANYA_MENIT)) {
            $this->selaraskanJendela($percakapan);
        }

        return $percakapan->windowIsOpen() ? null : $this->gagal($tolakan);
    }

    /**
     * Samakan catatan jendela kita dengan jawaban vendor.
     *
     * Yang disimpan adalah jawabannya apa adanya, termasuk saat vendor bilang
     * TUTUP: `window_expires_at` dimundurkan ke masa lalu, bukan dikosongkan.
     * Bedanya kelihatan saat menelusuri masalah — kolom kosong terbaca seperti
     * percakapan yang belum pernah menerima pesan masuk sama sekali, padahal
     * yang terjadi adalah jendelanya baru saja habis.
     */
    private function selaraskanJendela(CrmConversation $percakapan): void
    {
        $status = $this->chat->provider()->windowStatus($percakapan->contact_key);

        if (! ($status['success'] ?? false)) {
            return;   // vendor tak terjangkau — catatan lokal tetap dipakai
        }

        if (! ($status['is_open'] ?? false)) {
            $percakapan->forceFill(['window_expires_at' => now()->subSecond()])->save();

            return;
        }

        // Vendor bilang masih terbuka. Kalau ia menyebutkan sampai kapan,
        // angkanya dipakai — dialah yang berwenang, bukan hitungan kita.
        if ($kapan = $status['expires_at'] ?? null) {
            try {
                $percakapan->forceFill([
                    'window_expires_at' => Carbon::parse((string) $kapan)->setTimezone(config('app.timezone', 'UTC')),
                ])->save();
            } catch (\Throwable) {
                // Bentuk tanggal yang tak terbaca bukan alasan menutup jendela
                // yang vendor sendiri bilang terbuka.
            }
        }
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
