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
        /*
         * Centang. Peristiwa terpisah, dan sengaja ditangani SEBELUM saringan
         * pesan di bawah: `message.ack` tidak membawa isi pesan sama sekali,
         * jadi kalau ia sampai lolos ke rekam() ia akan melahirkan baris
         * kosong tanpa teks di tengah thread.
         */
        if ($event === 'message.ack') {
            if ($this->sesiUtama((string) ($amplop['session'] ?? ''))) {
                $this->perbaruiAck((array) ($amplop['payload'] ?? []));
            }

            return;
        }

        if (! in_array($event, ['message', 'message.any'], true)) {
            return;
        }

        if (! $this->sesiUtama((string) ($amplop['session'] ?? ''))) {
            return;
        }

        $payload = (array) ($amplop['payload'] ?? []);

        // "Kirim ke diri sendiri" di HP nomor utama bukan chat pelanggan.
        // Sejak chat dibaca dari `_data.key.remoteJid`, ia tak lagi tersaring
        // oleh `to` yang kosong — tanpa penjaga ini ia jadi thread bernomor
        // kita sendiri.
        $saya = PhoneNumber::normalize(strstr(self::teks(data_get($amplop, 'me.id')), '@', true) ?: '');
        $chat = $this->chatId($payload);

        if ($saya && $chat && PhoneNumber::normalize(strstr($chat, '@', true) ?: '') === $saya) {
            return;
        }

        $this->rekam($payload, null, hidup: true);
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
     *
     * @param  bool  $hidup  benar bila pesan ini baru saja tiba lewat webhook.
     *                Impor riwayat memakai jalur yang sama, dan HANYA penanda
     *                ini yang memisahkan "pesan baru masuk" dari "pesan lama
     *                sedang disalin" — tanpa itu impor menandai ratusan thread
     *                sebagai belum dibaca, padahal sudah dijawab berbulan lalu.
     */
    public function rekam(array $payload, ?string $chatIdPaksa = null, bool $hidup = false): ?CrmMessage
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
        $ubah = [];

        if (! $percakapan->last_message_at || $waktu->gt($percakapan->last_message_at)) {
            $ubah['last_message_at'] = $waktu;
        }

        /*
         * Lencana "belum dibaca". Cermin memang tidak menyentuh antrean triase
         * — tapi tanpa penanda apa pun, chat yang baru masuk tidak bisa
         * dibedakan dari 500 thread lama di daftar, dan CS baru tahu ada yang
         * menulis setelah membuka satu per satu.
         *
         * Balasan CS dari HP MENGOSONGKANNYA: pekerjaannya memang sudah
         * dikerjakan, cuma tidak di layar ini. Lencana yang tetap menyala
         * sesudah dijawab adalah cara tercepat membuat orang berhenti
         * mempercayainya.
         */
        if ($hidup) {
            $ubah['unread_count'] = $dariKita ? 0 : $percakapan->unread_count + 1;
        }

        if ($ubah) {
            $percakapan->forceFill($ubah)->save();
        }

        return $pesan;
    }

    /**
     * Perbarui centang satu pesan dari peristiwa `message.ack`.
     *
     * Hanya NAIK. WAHA tidak menjamin urutan kiriman webhook, dan ack yang
     * datang terlambat akan menurunkan centang biru kembali jadi abu-abu kalau
     * ditulis apa adanya — di layar itu terbaca seperti pelanggan "membatalkan"
     * bacaannya, kejadian yang tidak ada di WhatsApp.
     */
    public function perbaruiAck(array $payload): void
    {
        $id     = self::teks($payload['id'] ?? null);
        $status = self::statusDariAck($payload);

        if ($id === '' || $status === null) {
            return;
        }

        $pesan = CrmMessage::where('provider_message_id', 'waha:' . $id)->first();

        /*
         * BENTUK ID TIDAK SAMA DI KEDUA UJUNG — dan itu yang membuat centang
         * pesan kiriman ERP membeku di satu (dilaporkan 25 Sep 2026: di HP
         * sudah centang dua, di ERP masih satu).
         *
         * Jawaban /api/sendText memberi id PENDEK ('3EB0…'), sedangkan
         * `message.ack` mengabarkan bentuk PANJANG
         * ('true_628…@c.us_3EB0…'). Pencarian cocok-persis karenanya tak
         * pernah menemukan barisnya, dan tak ada galat apa pun yang muncul —
         * ack-nya cuma didiamkan seperti ack chat grup yang memang dibuang.
         *
         * Dicocokkan potongan terakhirnya, lalu idnya DIKANONIKALKAN ke bentuk
         * panjang supaya ack berikutnya (sampai → dibaca) cocok persis tanpa
         * perlu menebak lagi.
         */
        if (! $pesan) {
            $pesan = $this->barisKitaSendiri('waha:' . $id, $this->percakapanDariId($id));

            if ($pesan) {
                $pesan->forceFill(['provider_message_id' => 'waha:' . $id])->save();
            }
        }

        // Pesan yang belum pernah direkam (mis. ack menyusul chat grup yang
        // memang kita buang) bukan kesalahan — diamkan.
        if (! $pesan || $pesan->direction !== CrmMessage::KELUAR) {
            return;
        }

        if (self::peringkatCentang($status) <= self::peringkatCentang((string) $pesan->status)) {
            return;
        }

        $pesan->forceFill(['status' => $status])->save();
    }

    /**
     * Baris pesan KELUAR milik kita yang menunjuk pesan WAHA yang sama, walau
     * bentuk idnya berbeda.
     *
     * Dicocokkan lewat potongan terakhir setelah '_' — itulah bagian yang
     * tetap sama di kedua bentuk ('3EB0…'). Dibatasi ke arah KELUAR dan ke
     * baris yang idnya belum berbentuk panjang, supaya pencarian ini tidak
     * pernah menyerempet pesan masuk milik orang lain.
     */
    private function barisKitaSendiri(string $idPanjang, ?int $percakapanId): ?CrmMessage
    {
        /*
         * WAJIB DIJANGKARKAN KE SATU PERCAKAPAN, dan ini bukan sekadar
         * kerapian melainkan dua hal sekaligus.
         *
         * KECEPATAN. `LIKE '%…'` berawalan jokar tidak bisa memakai indeks:
         * tanpa jangkar ia memindai SELURUH `crm_messages` — puluhan ribu baris
         * setelah riwayat diimpor. `message.ack` datang untuk setiap tanda
         * terima di semua chat, dan kebanyakan tidak punya barisnya di sini,
         * jadi justru jalur gagal inilah yang paling sering ditempuh. Sekali
         * pindaian penuh per ack sudah cukup membuat webhook menumpuk sampai
         * pesan masuk terlambat sampai ke layar. Dengan `conversation_id`
         * di depan, yang dipindai tinggal puluhan baris milik chat itu.
         *
         * KEBENARAN. Potongan id tanpa jangkar bisa cocok dengan pesan di
         * percakapan LAIN yang kebetulan berakhiran sama — dan yang tertimpa
         * status centangnya adalah pesan ke orang yang salah.
         */
        if (! $percakapanId) {
            return null;
        }

        $potongan = substr($idPanjang, (int) strrpos($idPanjang, '_') + 1);

        // Potongan yang terlalu pendek bukan sidik yang bisa dipercaya —
        // lebih baik gelembung kembar daripada menimpa pesan yang salah.
        if (strlen($potongan) < 8) {
            return null;
        }

        return CrmMessage::where('conversation_id', $percakapanId)
            ->where('direction', CrmMessage::KELUAR)
            ->where(fn ($q) => $q
                ->where('provider_message_id', 'waha:' . $potongan)
                ->orWhere('provider_message_id', 'like', '%\_' . $potongan))
            ->latest('id')
            ->first();
    }

    /**
     * Percakapan cermin yang dituju sebuah id pesan WAHA bentuk panjang.
     *
     * Bentuknya `true_628xxx@c.us_3EB0…` — nomor lawan bicara ada di tengah,
     * jadi idnya sendiri sudah cukup untuk menemukan threadnya tanpa menebak
     * dari field `from`/`to` yang artinya berbalik tergantung siapa pengirim.
     */
    private function percakapanDariId(string $idPanjang): ?int
    {
        $bagian = explode('_', $idPanjang);

        if (count($bagian) < 3) {
            return null;   // bentuk pendek: tak ada nomor di dalamnya
        }

        $nomor = PhoneNumber::normalize(strstr($bagian[1], '@', true) ?: $bagian[1]);

        if (! $nomor) {
            return null;
        }

        return CrmConversation::where('channel', CrmConversation::KANAL_CERMIN)
            ->where('contact_key', $nomor)
            ->value('id');
    }

    /**
     * Tingkat ack WAHA → kosakata `status` yang sudah dipakai jalur resmi,
     * supaya `CrmMessage::centang()` tidak perlu tahu pesan ini datang dari
     * jalur yang mana.
     *
     * `ackName` didahulukan karena ia yang terbaca manusia; angkanya dipakai
     * sebagai cadangan kalau WAHA suatu saat mengirim salah satunya saja.
     * PENDING sengaja menghasilkan null: "sudah masuk antrean HP" bukan kabar
     * yang pantas digambar sebagai centang.
     */
    public static function statusDariAck(array $payload): ?string
    {
        $nama = strtoupper(self::teks($payload['ackName'] ?? null));

        if ($nama === '') {
            $angka = $payload['ack'] ?? null;

            if (! is_numeric($angka)) {
                return null;
            }

            $nama = match ((int) $angka) {
                -1      => 'ERROR',
                0       => 'PENDING',
                1       => 'SERVER',
                2       => 'DEVICE',
                3       => 'READ',
                4       => 'PLAYED',
                default => '',
            };
        }

        return match ($nama) {
            'SERVER'         => 'terkirim',
            'DEVICE'         => 'delivered',
            'READ', 'PLAYED' => 'read',
            'ERROR'          => 'failed',
            default          => null,
        };
    }

    /**
     * Urutan maju centang. 'failed' ditaruh paling atas supaya kabar buruk
     * tidak pernah tertimbun ack lama yang menyusul — pesan yang gagal berangkat
     * harus tetap terlihat gagal.
     */
    private static function peringkatCentang(string $status): int
    {
        return match ($status) {
            'terkirim'  => 1,
            'delivered' => 2,
            'read'      => 3,
            'failed'    => 4,
            default     => 0,
        };
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
        /*
         * NOWEB: `_data.key.remoteJid` adalah id CHAT-nya, apa pun arah
         * pesannya. Dua bentuk payload webhook-nya tidak cocok dengan tebakan
         * from/to di bawah (DIVERIFIKASI di server 24 Sep 2026, 40 dari 40
         * pesan pelanggan hari itu terbuang diam-diam):
         *   • chat beralamat LID (`addressingMode: lid`) — `from` berisi
         *     '…@lid', nomor aslinya hanya ada di `remoteJidAlt`;
         *   • pesan dari HP kita — `to` KOSONG, chatnya justru di `from`.
         * '@lid' tetap tidak pernah dipakai sebagai nomor; tanpa
         * `remoteJidAlt` pesannya tetap dilewati seperti sebelumnya.
         */
        $kunci = (array) data_get($payload, '_data.key', []);
        $jid   = self::teks($kunci['remoteJid'] ?? null);

        if (str_ends_with($jid, '@lid')) {
            $jid = self::teks($kunci['remoteJidAlt'] ?? null);
        }

        if ($jid !== '') {
            return $this->saring($jid);
        }

        $id = (bool) ($payload['fromMe'] ?? false)
            ? self::teks($payload['to'] ?? null)
            : self::teks($payload['from'] ?? null);

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
        $nama = $dariKita ? null : self::teks($payload['notifyName'] ?? data_get($payload, '_data.notifyName'));

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
        $id = self::teks($payload['id'] ?? null);

        if ($id === '') {
            return null;   // tanpa id tak ada penangkal kembar; lebih baik lewat.
        }

        $id = 'waha:' . $id;

        if (CrmMessage::where('provider_message_id', $id)->exists()) {
            return null;
        }

        /*
         * GEMA PESAN YANG KITA KIRIM SENDIRI (sejak Tahap 7).
         *
         * Balasan yang berangkat dari ERP kembali lagi ke sini sebagai
         * `fromMe`, dan barisnya sudah ada. Yang TIDAK bisa diandalkan adalah
         * idnya berbentuk sama: jawaban /api/sendText memberi id pendek
         * ('3EB0...'), sedangkan webhook membawa bentuk panjang
         * ('true_628…@c.us_3EB0…'). Kalau hanya dicocokkan persis, setiap
         * balasan muncul DUA KALI di thread — dan tak ada galat apa pun yang
         * menunjukkan kenapa.
         *
         * Pelajaran 24 Sep berlaku di sini: jangan percaya bentuk field
         * payload WAHA. Yang dicocokkan potongan terakhirnya, dan begitu
         * ketemu, id baris kita DIKANONIKALKAN ke bentuk webhook — supaya
         * `message.ack` yang menyusul (yang juga memakai bentuk panjang)
         * menemukan barisnya dan centangnya bisa bergerak.
         */
        if ($dariKita && ($kembar = $this->barisKitaSendiri($id, $percakapan->id))) {
            $kembar->forceFill(['provider_message_id' => $id])->save();

            return null;
        }

        try {
            return CrmMessage::create([
                'conversation_id'     => $percakapan->id,
                'direction'           => $dariKita ? CrmMessage::KELUAR : CrmMessage::MASUK,
                'message_type'        => $this->jenis($payload),
                'content'             => $this->isi($payload),
                /*
                 * Yang sampai di sini SELALU ketikan dari HP: sejak Tahap 7
                 * ERP memang bisa mengirim ke kanal ini, tapi kiriman ERP
                 * sudah punya barisnya sendiri dan diserap sebagai gema di
                 * atas. Menandainya WHATSAPP_APP karenanya tetap jujur —
                 * laporan "kebocoran balas dari HP" menghitung yang benar.
                 */
                'source'              => $dariKita ? CrmMessage::SOURCE_WHATSAPP_APP : null,
                'provider_message_id' => $id,
                /*
                 * Centang diambil dari `ack` yang SUDAH ikut di payload
                 * `message.any` — tidak perlu menunggu peristiwa terpisah.
                 * Hanya untuk pesan kita sendiri: `centang()` memang
                 * mengabaikan pesan masuk, dan `ack` pada pesan masuk adalah
                 * tanda baca KITA, bukan kabar yang berguna di layar.
                 */
                'status'              => $dariKita ? self::statusDariAck($payload) : null,
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
        $url   = self::teks($media['url'] ?? null);
        // ⚠️ `media.error` datang sebagai OBJEK di payload sungguhan, bukan
        // string (dibuktikan 24 Sep 2026: impor mati dengan "Array to string
        // conversion" di baris ini). Isinya tetap dipertahankan sebagai JSON —
        // alasan kegagalan unduh itu justru yang dicari orang saat menelusuri
        // lampiran yang tak muncul.
        $galat = self::teks($media['error'] ?? null, true);

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
            'original_name'  => ($nama = self::teks($media['filename'] ?? null)) !== '' ? $nama : null,
            'mime'           => ($mime = self::teks($media['mimetype'] ?? null)) !== '' ? $mime : null,
        ]);
    }

    /** Jenis pesan untuk gelembung; disimpulkan dari mime bila WAHA tak menyebutnya. */
    private function jenis(array $payload): string
    {
        $jenis = self::teks($payload['type'] ?? data_get($payload, '_data.type'));

        if ($jenis !== '') {
            return $jenis;
        }

        if (! ($payload['hasMedia'] ?? false)) {
            return 'text';
        }

        $mime = self::teks(data_get($payload, 'media.mimetype'));

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'document',
        };
    }

    private function isi(array $payload): ?string
    {
        $isi = self::teks($payload['body'] ?? data_get($payload, '_data.caption'));

        return $isi !== '' ? $isi : null;
    }

    /**
     * Baca satu nilai payload sebagai teks, APA PUN bentuknya.
     *
     * Ada karena payload WAHA sungguhan membawa OBJEK di tempat yang
     * dokumentasinya menyebut string — `media.error` terbukti begitu, dan
     * impor riwayat mati total di tengah jalan karenanya ("Array to string
     * conversion"). Satu field bentuknya tak terduga tidak boleh menjatuhkan
     * seluruh impor 500 chat; itu kegagalan yang tidak sebanding dengan
     * sebabnya.
     *
     * Objek yang membungkus satu nilai (bentuk yang dipakai WAHA untuk id)
     * dibuka lewat kunci yang dikenal. Sisanya dikembalikan kosong — KECUALI
     * bila $json, dipakai untuk pesan galat: di situ isinya justru yang dicari
     * orang saat menelusuri lampiran yang tak muncul, jadi lebih baik JSON
     * yang berantakan daripada keterangan yang hilang.
     */
    public static function teks(mixed $nilai, bool $json = false): string
    {
        if (is_string($nilai)) {
            return trim($nilai);
        }

        if (is_int($nilai) || is_float($nilai)) {
            return (string) $nilai;
        }

        if (is_array($nilai)) {
            foreach (['_serialized', 'id', 'message', 'text', 'body'] as $kunci) {
                if (isset($nilai[$kunci]) && is_scalar($nilai[$kunci])) {
                    return trim((string) $nilai[$kunci]);
                }
            }

            return $json ? mb_substr((string) json_encode($nilai), 0, 500) : '';
        }

        return '';
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
