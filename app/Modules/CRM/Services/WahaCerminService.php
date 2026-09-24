<?php

namespace App\Modules\CRM\Services;

use App\Models\Customer;
use App\Models\CrmSetting;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Support\PeranWaha;
use App\Modules\CRM\Support\PhoneNumber;
use Carbon\Carbon;

/**
 * CERMIN BACA-SAJA: chat nomor utama (WAHA) ditulis ke inbox ERP apa adanya.
 *
 * Kembaran IncomingWebhookService, sengaja TIDAK dilebur dengannya. Dua jalur
 * ini berbeda pada hampir setiap bentuk field (WAHA: `payload.from`,
 * `fromMe`, `@c.us`; api.co.id: `data.customer_phone`, `event_type`), dan yang
 * lebih menentukan: yang satu MEMBUKA jendela 24 jam serta memanggil
 * notifikasi, yang satu ini TIDAK BOLEH menyentuh apa pun.
 *
 * ────────────────────────── ATURAN TAHAP 6 ──────────────────────────
 * Cermin ini NOL EFEK SAMPING. Yang ditulis hanya pesan, lampiran, dan waktu
 * pesan terakhir (supaya threadnya bisa diurutkan). Yang TIDAK pernah
 * disentuh, dan alasannya masing-masing:
 *
 *   • `unread_count` — antrean triase adalah janji "ini bisa kamu kerjakan
 *     dari sini". Thread cermin belum bisa dibalas dari ERP, jadi menandainya
 *     belum-dibaca berarti menumpuk pekerjaan yang tidak punya tombol, dan
 *     antreannya berhenti dipercaya. (`queue_state` disetel SEKALI saat
 *     percakapan lahir — lihat percakapan(); bawaan kolomnya justru
 *     'menunggu_kita', jadi MEMBIARKANNYA adalah kesalahan yang sama.)
 *   • `window_expires_at` — jendela 24 jam itu milik jalur BERBAYAR. Chat
 *     lewat WAHA tidak membuka apa pun di sisi Meta; menulisnya di sini
 *     berarti menjanjikan kotak ketik yang akan ditolak saat dipakai.
 *   • NotifikasiChatService — tidak ada yang bisa ditindaklanjuti, jadi
 *     membunyikannya hanya melatih orang mengabaikan notifikasi.
 *
 * Kalau nanti cermin ini jadi dua-arah (Tahap 7+), ketiganya dibuka bersama
 * dengan kotak ketiknya — jangan sebagian.
 */
class WahaCerminService
{
    /**
     * Akhiran chat PERORANGAN — daftar putih, bukan daftar hitam.
     *
     * Dua bentuk, dan keduanya wajib: '@c.us' adalah bentuk yang dipakai WAHA
     * di payload webhook, sedangkan '@s.whatsapp.net' adalah bentuk asli
     * NOWEB yang muncul di endpoint /chats (DIVERIFIKASI di server 24 Sep
     * 2026: 643 dari 656 chat berakhiran @s.whatsapp.net). Menebak salah satu
     * saja berarti seluruh pesan dibuang DIAM-DIAM — tanpa galat, tanpa baris
     * gagal, tanpa gejala apa pun selain inbox yang terlihat seperti hari sepi.
     *
     * Ditulis sebagai daftar putih supaya bentuk yang belum dikenal ikut
     * DITOLAK, bukan diterima: '@g.us' (grup), 'status@broadcast',
     * '@newsletter', dan '@lid' semuanya bukan chat pelanggan, dan '@lid'
     * khususnya bukan nomor telepon sama sekali — ia akan melahirkan
     * percakapan bernomor palsu kalau lolos.
     */
    private const AKHIRAN_ORANG = ['@c.us', '@s.whatsapp.net'];

    /**
     * @param  array  $amplop  badan webhook WAHA apa adanya
     */
    public function tangani(array $amplop): void
    {
        $event = (string) ($amplop['event'] ?? '');

        // 'message' (masuk saja) DAN 'message.any' (termasuk kiriman kita dari
        // HP) sama-sama diterima: mana yang dipasang di WAHA bisa berubah, dan
        // menerima keduanya tidak berisiko karena idempotensinya ada di
        // `provider_message_id` yang unik — satu pesan yang datang lewat dua
        // peristiwa tetap jadi satu baris.
        if (! in_array($event, ['message', 'message.any'], true)) {
            return;
        }

        if (! $this->sesiUtama((string) ($amplop['session'] ?? ''))) {
            return;
        }

        $this->rekam((array) ($amplop['payload'] ?? []));
    }

    /**
     * Rekam SATU pesan WAHA ke cermin. Null bila dilewati (bukan chat
     * perorangan, nomor tak terbaca, atau sudah pernah direkam).
     *
     * Dipakai webhook DAN impor riwayat (crm:impor-cermin-waha) — sengaja satu
     * jalur. Kalau impor menyusun barisnya sendiri, dua tempat harus diingat
     * bersamaan setiap kali bentuk penyimpanan berubah, dan yang satu akan
     * menyimpang diam-diam: hasilnya riwayat lama yang tampil beda dari pesan
     * baru di thread yang sama.
     *
     * @param  ?string  $chatIdPaksa  id chat dari pemanggil. Endpoint riwayat
     *                  WAHA tidak selalu membawa `from`/`to` yang lengkap,
     *                  sedangkan pengimpor SUDAH tahu chat mana yang sedang
     *                  ditarik — menebaknya ulang dari payload hanya menambah
     *                  satu cara gagal yang tidak perlu ada.
     */
    public function rekam(array $payload, ?string $chatIdPaksa = null): ?CrmMessage
    {
        $chatId = $chatIdPaksa !== null ? $this->saring($chatIdPaksa) : $this->chatId($payload);

        if (! $chatId) {
            return null;
        }

        $nomor = PhoneNumber::normalize(strstr($chatId, '@', true) ?: '');

        if (! $nomor) {
            return null;
        }

        $dariKita = (bool) ($payload['fromMe'] ?? false);
        $waktu    = $this->waktu($payload['timestamp'] ?? null);

        $percakapan = $this->percakapan($nomor, $payload, $dariKita);
        $pesan      = $this->simpanPesan($percakapan, $payload, $dariKita, $waktu);

        if (! $pesan) {
            return null;   // kembar, sudah ditolak basis data
        }

        $this->simpanLampiran($pesan, $payload);

        /*
         * Satu-satunya kolom percakapan yang boleh bergerak — lihat catatan
         * kelas. Daftar inbox mengurutkan dengan COALESCE(last_message_at,
         * created_at), jadi tanpa ini thread cermin membeku di dasar daftar.
         *
         * Hanya MAJU. Impor riwayat menyusuri pesan lama, dan tanpa penjaga
         * ini setiap chat yang diimpor akan terlempar ke dasar daftar dengan
         * tanggal pesan tertuanya — padahal ia baru saja aktif kemarin.
         */
        if (! $percakapan->last_message_at || $waktu->gt($percakapan->last_message_at)) {
            $percakapan->forceFill(['last_message_at' => $waktu])->save();
        }

        return $pesan;
    }

    /**
     * Hanya sesi yang terdaftar sebagai peran UTAMA yang dicermin.
     *
     * Nomor notifikasi juga menerima balasan ("iya kak", "stop"), tapi
     * balasannya sudah punya jalur sendiri berupa balasan otomatis yang
     * mengarahkan ke nomor chat. Mencerminkannya ke inbox hanya akan mengisi
     * daftar dengan thread yang tak seorang pun ditugasi membacanya.
     */
    private function sesiUtama(string $sesi): bool
    {
        if ($sesi === '') {
            return false;
        }

        return $sesi === CrmSetting::for('waha')->namaSesi(PeranWaha::UTAMA);
    }

    /**
     * Nomor lawan bicara: `to` bila pesannya dari kita, `from` bila dari
     * pelanggan. Null untuk grup, status, dan newsletter.
     *
     * ⚠️ Penyaringnya bukan kerapian melainkan penjaga: nomor utama ada di
     * grup pelanggan dan grup internal, dan tanpa saringan ini setiap obrolan
     * grup ikut tumpah ke inbox sebagai "percakapan" yang mustahil dilayani —
     * sekaligus membocorkan isi grup internal ke layar yang dibuka seluruh
     * tim CS.
     */
    private function chatId(array $payload): ?string
    {
        $id = (bool) ($payload['fromMe'] ?? false)
            ? (string) ($payload['to'] ?? '')
            : (string) ($payload['from'] ?? '');

        return $this->saring($id);
    }

    /** Id chat bila ia chat perorangan; null untuk grup, status, newsletter, @lid. */
    private function saring(string $id): ?string
    {
        return self::perorangan($id) ? $id : null;
    }

    /**
     * Chat perorangan? Publik & statis karena pengimpor riwayat
     * (crm:impor-cermin-waha) menyaring daftar chatnya SEBELUM menarik pesan —
     * menyalin daftar akhirannya ke sana berarti dua tempat harus diingat
     * bersamaan, dan yang satu pasti tertinggal saat bentuk id berubah lagi.
     */
    public static function perorangan(string $id): bool
    {
        foreach (self::AKHIRAN_ORANG as $akhiran) {
            if (str_ends_with($id, $akhiran)) {
                return true;
            }
        }

        return false;
    }

    /** Thread cermin milik nomor ini — dibuat bila belum ada. */
    private function percakapan(string $nomor, array $payload, bool $dariKita): CrmConversation
    {
        /*
         * `queue_state` ditetapkan SAAT LAHIR, dan hanya saat lahir.
         *
         * Kolomnya berbawaan 'menunggu_kita' di basis data, jadi "tidak
         * menyentuh antrean" justru menaruh setiap thread cermin di antrean
         * triase — persis yang tidak boleh terjadi. Thread cermin belum punya
         * tombol balas, dan pekerjaan yang tak bisa dikerjakan dari tempat ia
         * ditumpuk adalah cara tercepat membuat antrean berhenti dipercaya.
         *
         * 'dingin' dipilih karena itu satu-satunya nilai yang jujur: bola
         * bukan di kita (tak ada yang bisa kita lakukan dari sini) dan bukan
         * di pelanggan (ia sudah menulis). Sesudah lahir, kolom ini tidak
         * pernah disentuh lagi — CS boleh memindahkannya sendiri.
         */
        $percakapan = CrmConversation::findOrCreateFor(
            $nomor,
            CrmConversation::KANAL_CERMIN,
            null,
            ['queue_state' => CrmConversation::QUEUE_DINGIN]
        );

        $ubah = [];

        /*
         * Nama profil WhatsApp hanya dipakai sebagai label layar, dan hanya
         * bila belum ada nama sama sekali. `namaUntukPesan()` tetap menolaknya
         * untuk menyapa — lihat catatan di model: "Halo Kak Toko Berkah Jaya"
         * terbaca seperti salah sasaran.
         */
        $nama = $dariKita ? null : trim((string) ($payload['notifyName'] ?? data_get($payload, '_data.notifyName') ?? ''));

        if ($nama && ! $percakapan->display_name) {
            $ubah['display_name'] = $nama;
            $ubah['name_source']  = CrmConversation::NAMA_WHATSAPP;
        }

        // Pencocokan ke master pelanggan memakai jalur yang SAMA dengan webhook
        // resmi — dipinjam, bukan disalin, supaya aturan ekor 9 digit, nomor
        // cabang, dan nomor notifikasi tambahan tidak pernah menyimpang antar
        // kanal.
        if (! $percakapan->customer_id) {
            if ($customer = $this->cariPelanggan($percakapan->contact_key)) {
                $ubah['customer_id'] = $customer->id;
            }
        }

        if ($ubah) {
            $percakapan->forceFill($ubah)->save();
        }

        return $percakapan;
    }

    private function cariPelanggan(string $contactKey): ?Customer
    {
        return app(IncomingWebhookService::class)->cocokkanPelanggan($contactKey);
    }

    /**
     * Simpan satu pesan. Null bila idnya sudah ada.
     *
     * Id WAHA diberi awalan 'waha:' sebelum masuk `provider_message_id`.
     * Kolom itu unik SE-TABEL, bukan per kanal, dan bentuk id WAHA
     * ('false_628…@c.us_3EB0…') memang tak mirip id vendor resmi — tapi
     * mengandalkan ketidakmiripan itu berarti menaruh keutuhan data pada
     * kebetulan. Awalannya juga membuat asal sebuah baris terbaca langsung
     * dari basis data saat menelusuri masalah.
     */
    private function simpanPesan(CrmConversation $percakapan, array $payload, bool $dariKita, Carbon $waktu): ?CrmMessage
    {
        $id = trim((string) ($payload['id'] ?? ''));

        if ($id === '') {
            return null;   // tanpa id tak ada penangkal kembar; lebih baik lewat.
        }

        $id = 'waha:' . $id;

        if (CrmMessage::where('provider_message_id', $id)->exists()) {
            return null;
        }

        try {
            return CrmMessage::create([
                'conversation_id'     => $percakapan->id,
                'direction'           => $dariKita ? CrmMessage::KELUAR : CrmMessage::MASUK,
                'message_type'        => $this->jenis($payload),
                'content'             => $this->isi($payload),
                /*
                 * Kiriman kita di cermin SELALU berasal dari HP — ERP belum
                 * bisa mengirim ke kanal ini sama sekali. Menandainya
                 * WHATSAPP_APP membuat laporan "kebocoran balas dari HP" tetap
                 * jujur alih-alih menghitungnya sebagai balasan ERP.
                 */
                'source'              => $dariKita ? CrmMessage::SOURCE_WHATSAPP_APP : null,
                'provider_message_id' => $id,
                /*
                 * `status` dibiarkan kosong untuk pesan kita sendiri. Centang
                 * di gelembung dibaca dari kolom ini, dan menuliskan
                 * 'terkirim' berarti mengaku tahu sesuatu yang belum
                 * dikabarkan siapa pun — peristiwa `message.ack` WAHA belum
                 * kita langgan di tahap ini.
                 */
                'status'              => null,
                'sent_at'             => $waktu,
                'raw'                 => $payload,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Dua kiriman kembar tiba nyaris bersamaan ('message' dan
            // 'message.any' atas pesan yang sama, misalnya).
            return null;
        }
    }

    /**
     * Catat lampiran — TANPA mengunduhnya di sini, sama seperti jalur resmi.
     * Unduhannya dikerjakan `crm:unduh-lampiran` beberapa detik kemudian.
     *
     * ⚠️ `media.url` menunjuk server berkas WAHA di 127.0.0.1 dan hanya bisa
     * diambil dari server yang sama. Itu juga berarti ia tidak kedaluwarsa
     * seperti media Meta — tapi ikut terhapus saat volume WAHA dibersihkan,
     * jadi menundanya tetap bukan alasan untuk tidak pernah mengunduhnya.
     */
    private function simpanLampiran(CrmMessage $pesan, array $payload): void
    {
        if (! ($payload['hasMedia'] ?? false)) {
            return;
        }

        $media = (array) ($payload['media'] ?? []);
        $url   = trim((string) ($media['url'] ?? ''));
        $galat = trim((string) ($media['error'] ?? ''));

        /*
         * Barisnya tetap DIBUAT walau tak ada URL. Pelanggan memang mengirim
         * sesuatu, dan gelembung yang diam-diam kosong membuat CS mengira tak
         * ada apa-apa — persis jebakan yang sudah dibayar sekali di jalur
         * resmi (lihat IncomingWebhookService::simpanLampiran).
         */
        CrmAttachment::create([
            'message_id'     => $pesan->id,
            'source_url'     => $url !== '' ? $url : null,
            'download_error' => $url !== '' ? null : ($galat !== ''
                ? 'WAHA gagal mengambil media ini: ' . $galat
                : 'Media tidak disertakan WAHA — pastikan pengunduhan media menyala di container.'),
            'original_name'  => ($nama = trim((string) ($media['filename'] ?? ''))) !== '' ? $nama : null,
            'mime'           => ($mime = trim((string) ($media['mimetype'] ?? ''))) !== '' ? $mime : null,
        ]);
    }

    /** Jenis pesan untuk gelembung; disimpulkan dari mime bila WAHA tak menyebutnya. */
    private function jenis(array $payload): string
    {
        $jenis = trim((string) ($payload['type'] ?? data_get($payload, '_data.type') ?? ''));

        if ($jenis !== '') {
            return $jenis;
        }

        if (! ($payload['hasMedia'] ?? false)) {
            return 'text';
        }

        $mime = (string) data_get($payload, 'media.mimetype', '');

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'document',
        };
    }

    private function isi(array $payload): ?string
    {
        $isi = trim((string) ($payload['body'] ?? data_get($payload, '_data.caption') ?? ''));

        return $isi !== '' ? $isi : null;
    }

    /**
     * Waktu menurut WAHA — detik Unix, bukan ISO.
     *
     * ⚠️ Dikonversi ke zona aplikasi sebelum disimpan. Jebakan yang sama
     * dengan jalur resmi: jam UTC yang ditulis apa adanya ke kolom yang dibaca
     * sebagai Asia/Jakarta membuat seluruh thread meleset 7 jam, dan pesan
     * pagi tampil sebagai pesan tengah malam kemarin.
     */
    private function waktu(mixed $detik): Carbon
    {
        if (! is_numeric($detik) || (int) $detik <= 0) {
            return now();
        }

        return Carbon::createFromTimestamp((int) $detik)
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
