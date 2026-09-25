<?php

namespace App\Modules\CRM\Providers;

use App\Models\CrmSetting;
use App\Modules\CRM\Contracts\ChatProvider;
use App\Modules\CRM\Services\WahaCerminService;
use App\Modules\CRM\Support\PeranWaha;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\CRM\Support\WahaClient;

/**
 * Adapter CHAT lewat WAHA — nomor UTAMA, jalur tidak resmi (Tahap 7).
 *
 * Bedanya dengan WahaProvider (yang mengirim NOTIFIKASI) bukan teknis
 * melainkan peran, dan pemisahannya disengaja:
 *  - WahaProvider memegang sesi `notifikasi`, mengirim kalimat yang WAJIB
 *    dirangkai dari TemplateResmi, dan menolak berjalan dari peran lain.
 *  - Berkas ini memegang sesi `utama` — nomor yang sudah dikenal pelanggan —
 *    dan yang dikirimnya kalimat bebas yang diketik manusia.
 * Menyatukannya berarti satu salah kabel bisa membuat blast notifikasi
 * berangkat dari identitas toko, dan itu persis risiko yang seluruh pemisahan
 * dua nomor ini dibangun untuk mencegah.
 *
 * YANG TIDAK ADA DI JALUR INI, dan itu fitur bukan kekurangan:
 *  - **Jendela 24 jam.** Milik Meta, bukan milik WhatsApp. Perangkat tertaut
 *    boleh mengirim kapan saja, persis seperti WhatsApp Web di HP CS. Karena
 *    itu windowStatus() selalu menjawab terbuka — dan itu jawaban JUJUR,
 *    bukan siasat melewati penjaga.
 *  - **Template.** Tak ada yang meninjau, tak ada yang menagih. Pesan template
 *    tetap bisa dikirim, tapi yang berangkat adalah BUNYINYA sebagai teks.
 *  - **Tombol balasan cepat.** WhatsApp hanya memberikannya ke jalur resmi.
 *
 * WARNING: yang dipertaruhkan di sini adalah nomor 08998844666 — identitas
 * toko yang tak tergantikan, beda dari nomor notifikasi yang sekali pakai.
 * Jalur ini hanya untuk MEMBALAS orang yang menghubungi kita. Blast promosi
 * lewat sini adalah cara tercepat kehilangan nomor itu selamanya.
 */
class WahaChatProvider implements ChatProvider
{
    private CrmSetting $setting;

    private WahaClient $klien;

    public function __construct(?CrmSetting $setting = null)
    {
        $this->setting = $setting ?: CrmSetting::for('waha');
        $this->klien   = new WahaClient($this->setting);
    }

    public function key(): string
    {
        return 'waha';
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    /** Sesi WAHA nomor utama. Dikunci ke peran UTAMA, tidak bisa digeser. */
    public function sesi(): string
    {
        return $this->setting->namaSesi(PeranWaha::UTAMA);
    }

    /* ------------------------------------------------------------- mengirim */

    public function sendText(array $payload): array
    {
        if ($salah = $this->periksaTujuan($payload['to'] ?? null)) {
            return $salah;
        }

        $teks = trim((string) ($payload['text'] ?? ''));

        if ($teks === '') {
            return $this->gagal('Pesan kosong.');
        }

        return $this->kirim('/api/sendText', [
            'chatId' => $this->chatId((string) $payload['to']),
            'text'   => $teks,
            // Kartu pratinjau harus diminta; tanpa ini URL datang polos.
            'linkPreview' => str_contains($teks, 'http'),
        ], $payload['reply_to'] ?? null);
    }

    public function sendMedia(array $payload): array
    {
        if ($salah = $this->periksaTujuan($payload['to'] ?? null)) {
            return $salah;
        }

        $media = (string) ($payload['media'] ?? '');

        if ($media === '') {
            return $this->gagal('Berkas media tidak ada.');
        }

        /*
         * WAHA mengunduh sendiri berkasnya dari URL yang kita berikan — jadi
         * yang dikirim ERP adalah tautan bertanda tangan berumur pendek, sama
         * seperti ke jalur resmi. Bedanya cuma siapa yang mengambil.
         *
         * WARNING: tautan itu WAJIB bisa dibuka dari dalam container. WAHA
         * duduk di server yang sama tapi jaringannya terpisah, jadi yang
         * dipakai harus alamat publik ERP (APP_URL) — bukan localhost.
         */
        $jenis = (string) ($payload['type'] ?? 'document');

        $endpoint = match ($jenis) {
            'image' => '/api/sendImage',
            'video' => '/api/sendVideo',
            'audio' => '/api/sendVoice',
            default => '/api/sendFile',
        };

        $berkas = ['url' => $media];

        if ($nama = $payload['nama_berkas'] ?? null) {
            $berkas['filename'] = (string) $nama;
        }

        if ($mime = $payload['mime'] ?? null) {
            $berkas['mimetype'] = (string) $mime;
        }

        $body = [
            'chatId' => $this->chatId((string) $payload['to']),
            'file'   => $berkas,
        ];

        // Suara tidak mengenal caption; mengirimkannya membuat WAHA menolak
        // seluruh permintaan, bukan sekadar mengabaikan captionnya.
        if ($jenis !== 'audio' && ($caption = $payload['caption'] ?? null)) {
            $body['caption'] = (string) $caption;
        }

        return $this->kirim($endpoint, $body, $payload['reply_to'] ?? null);
    }

    /**
     * Template TETAP bisa dikirim, tapi yang berangkat bunyinya sebagai teks
     * biasa — di jalur ini tak ada yang meninjau dan tak ada yang menagih.
     *
     * Bunyinya dirangkai pemanggil dan diserahkan lewat 'text'; kalau tidak
     * ada, permintaannya DITOLAK alih-alih mengirim nama template mentah.
     * Pelanggan yang menerima "pesanan_dikirim" tanpa kalimat apa pun lebih
     * buruk daripada pesan yang tidak jadi dikirim.
     */
    public function sendTemplate(array $payload): array
    {
        $teks = trim((string) ($payload['text'] ?? ''));

        if ($teks === '') {
            return $this->gagal(
                'Template "' . ($payload['template'] ?? '(kosong)') . '" tidak punya bunyi teks untuk jalur '
                . 'self-host. Jalur ini mengirim kalimat, bukan nama template.'
            );
        }

        return $this->sendText(['to' => $payload['to'] ?? null, 'text' => $teks]);
    }

    /**
     * Tombol balasan cepat HANYA ada di jalur resmi — WhatsApp tidak
     * memberikannya ke perangkat tertaut.
     *
     * Ditolak tegas, bukan diturunkan diam-diam jadi teks biasa: pemanggil
     * satu-satunya adalah pancingan jendela 24 jam, dan jendela itu tidak ada
     * di sini. Pancingan yang "berhasil" di jalur ini berarti pelanggan
     * diganggu tanpa satu pun alasan yang membuat fiturnya ada.
     */
    public function sendInteraktif(array $payload): array
    {
        return $this->gagal(
            'Jalur self-host tidak mengenal tombol balasan cepat — dan tidak memerlukannya, '
            . 'karena jendela 24 jam tidak berlaku di sini.'
        );
    }

    /**
     * Tandai chat sudah dibaca, supaya notifikasi berhenti menumpuk di HP CS.
     *
     * WARNING: ini memunculkan CENTANG BIRU di HP pelanggan. Karena itu ia
     * TIDAK pernah dipanggil otomatis saat thread dibuka: chat yang cuma
     * diintip akan terbaca sebagai "sudah dilihat", dan "dibaca tapi
     * didiamkan" terasa lebih buruk bagi pelanggan daripada "belum dibaca".
     * Yang memicunya manusia yang menekan tombol, atau tidak sama sekali.
     *
     * @return array{success:bool, error:?string}
     */
    public function tandaiDibaca(string $nomor): array
    {
        if ($salah = $this->periksaTujuan($nomor)) {
            return ['success' => false, 'error' => $salah['error']];
        }

        $status = $this->klien->statusSesi($this->sesi());

        if (! $status['siap']) {
            return ['success' => false, 'error' => 'Sesi "' . $this->sesi() . '" berstatus ' . $status['status'] . '.'];
        }

        $res = $this->klien->request('post', '/api/sendSeen', [
            'session' => $this->sesi(),
            'chatId'  => $this->chatId($nomor),
        ]);

        return ['success' => $res['success'], 'error' => $res['success'] ? null : (string) $res['error']];
    }

    /* --------------------------------------------------------------- status */

    /**
     * SELALU terbuka, dan itu bukan siasat melewati penjaga.
     *
     * Jendela 24 jam adalah aturan penagihan Meta atas Cloud API; WhatsApp
     * sendiri tidak mengenalnya. Perangkat tertaut boleh mengirim kapan saja,
     * persis seperti CS mengetik dari HP-nya. Menjawab "tutup" di sini berarti
     * ERP menolak pekerjaan yang di HP jelas-jelas boleh dilakukan — dan CS
     * akan kembali membalas dari HP, yang membuat seluruh inbox ini sia-sia.
     */
    public function windowStatus(string $identifier): array
    {
        return [
            'success'    => true,
            'is_open'    => true,
            'expires_at' => null,
            'raw'        => ['alasan' => 'Jalur self-host tidak mengenal jendela 24 jam.'],
            'error'      => null,
        ];
    }

    public function phoneNumbers(): array
    {
        $res = $this->klien->request('get', '/api/sessions/' . rawurlencode($this->sesi()));

        if (! $res['success']) {
            return ['success' => false, 'numbers' => [], 'error' => (string) $res['error']];
        }

        $id = (string) (data_get($res['data'], 'me.id') ?: '');

        if ($id === '') {
            return ['success' => true, 'numbers' => [], 'error' => null];
        }

        // 'id' WAHA berbentuk 628xx@s.whatsapp.net / @c.us — yang dipakai ERP
        // nomornya saja, sama seperti business_number_id jalur resmi.
        $nomor = PhoneNumber::normalize(explode('@', $id)[0]);

        return [
            'success' => true,
            'numbers' => [[
                'id'              => $nomor ?: $id,
                'phone_number_id' => $nomor ?: null,
                'display'         => $nomor,
                'verified_name'   => (string) (data_get($res['data'], 'me.pushName') ?: ''),
                'quality_rating'  => null,
                'is_primary'      => true,
                'waba_id'         => null,
            ]],
            'error' => null,
        ];
    }

    public function profilKontak(string $identifier): array
    {
        $nomor = PhoneNumber::normalize($identifier);

        if (! $nomor) {
            return ['success' => false, 'name' => null, 'error' => 'Nomor tidak valid.'];
        }

        $res = $this->klien->request(
            'get',
            '/api/contacts?session=' . rawurlencode($this->sesi()) . '&contactId=' . rawurlencode($this->chatId($nomor))
        );

        if (! $res['success']) {
            return ['success' => false, 'name' => null, 'error' => (string) $res['error']];
        }

        $nama = data_get($res['data'], 'name')
            ?: data_get($res['data'], 'pushname')
            ?: data_get($res['data'], '0.name')
            ?: data_get($res['data'], '0.pushname');

        return ['success' => true, 'name' => is_string($nama) && $nama !== '' ? $nama : null, 'error' => null];
    }

    /* ---------------------------------------- yang memang tidak ada di sini */

    /**
     * Webhook WAHA duduk di konfigurasi SESI, bukan di daftar endpoint vendor,
     * dan sudah punya layarnya sendiri (tombol "Pasang Cermin" di Pengaturan).
     * Mengarangkan daftar kosong di sini membuat layar pemantau webhook
     * melaporkan "tidak ada masalah" untuk jalur yang tak pernah ia periksa.
     */
    public function webhooks(): array
    {
        return ['success' => false, 'endpoints' => [], 'error' => $this->takAda('Daftar webhook')];
    }

    public function enableWebhook(string $id): array
    {
        return ['success' => false, 'error' => $this->takAda('Menyalakan webhook')];
    }

    public function templates(): array
    {
        return ['success' => false, 'templates' => [], 'error' => $this->takAda('Daftar template Meta')];
    }

    public function buatTemplate(string $nama, string $kategori, string $body, array $variabel = [], string $bahasa = 'id', array $tombol = []): array
    {
        return ['success' => false, 'id' => null, 'status' => null, 'error' => $this->takAda('Pengajuan template')];
    }

    /**
     * Tidak ada unggahan terpisah: WAHA mengambil sendiri berkasnya dari URL
     * yang diberikan saat sendMedia.
     */
    public function uploadMedia(string $absolutePath, string $mime, string $filename): array
    {
        return ['success' => false, 'media_id' => null, 'error' => $this->takAda('Unggah media terpisah')];
    }

    /* -------------------------------------------------------------- bantuan */

    /**
     * chatId NOWEB. Dipakai '@c.us' — bentuk yang sudah TERBUKTI diterima WAHA
     * di jalur notifikasi yang berjalan di server sejak Agustus.
     *
     * Ini sengaja BEDA dari sisi terima: webhook masuk datang sebagai
     * '@s.whatsapp.net' dan saringan cermin harus menerima keduanya. Yang satu
     * soal apa yang WAHA kirimkan, yang satu soal apa yang ia terima —
     * menyamakannya "supaya rapi" pernah membuang setiap pesan masuk.
     */
    private function chatId(string $nomor): string
    {
        return PhoneNumber::normalize($nomor) . '@c.us';
    }

    /** @return array{success:bool, message_id:null, customer_id:null, raw:array, error:string}|null */
    private function periksaTujuan(?string $nomor): ?array
    {
        if (! $this->isReady()) {
            return $this->gagal('Jalur WhatsApp self-host belum dikonfigurasi.');
        }

        $to = PhoneNumber::normalize($nomor);

        if (! $to) {
            return $this->gagal('Nomor tujuan tidak valid.');
        }

        /*
         * Daftar putih berlaku di sini JUGA. Isinya sama dengan yang menjaga
         * jalur notifikasi, dan alasannya sama: selama uji nyata, satu salah
         * ketik nomor berarti orang asing menerima pesan dari nomor toko.
         */
        $allowed = (array) config('crm.allowed_recipients', []);

        if ($allowed && ! in_array($to, $allowed, true)) {
            return $this->gagal("Nomor {$to} tidak ada di daftar putih penerima (config crm.allowed_recipients).");
        }

        return null;
    }

    /**
     * Kirim, dengan status sesi diperiksa DULU.
     *
     * Tanpa pemeriksaan itu, pesan yang berangkat saat sesi mati ditolak WAHA
     * dengan galat yang bentuknya berubah-ubah — dan admin membaca "gagal
     * mengirim" tanpa tahu bahwa yang perlu ia lakukan adalah memindai QR.
     */
    private function kirim(string $endpoint, array $body, ?string $replyTo): array
    {
        $status = $this->klien->statusSesi($this->sesi());

        if (! $status['siap']) {
            return $this->gagal(
                'Nomor utama sedang tidak tersambung (sesi "' . $this->sesi() . '" berstatus '
                . $status['status'] . '). Pesan TIDAK dikirim.'
            );
        }

        $body['session'] = $this->sesi();

        if ($replyTo) {
            // Cermin menyimpan id ber-awalan 'waha:'; yang dikenal WAHA id
            // aslinya. Tanpa pengupasan ini kutipan ditolak diam-diam dan
            // pesannya berangkat tanpa konteks yang sengaja dipilih admin.
            $body['reply_to'] = str_starts_with($replyTo, 'waha:') ? substr($replyTo, 5) : $replyTo;
        }

        $res = $this->klien->request('post', $endpoint, $body);

        if (! $res['success']) {
            return $this->gagal((string) $res['error']);
        }

        /*
         * BENTUK PANJANG DIDAHULUKAN, dan urutan ini yang menentukan apakah
         * centang bisa bergerak.
         *
         * `message.ack` yang menyusul kemudian mengabarkan id bentuk panjang
         * ('true_628…@c.us_3EB0…'). Kalau yang kita simpan bentuk pendeknya,
         * ack itu tak pernah menemukan barisnya — centang membeku di satu
         * walau di HP pelanggan sudah dua, tanpa satu pun galat yang muncul.
         *
         * Dibaca lewat WahaCerminService::teks() karena `id` bisa datang
         * sebagai OBJEK ({fromMe, remote, id, _serialized}), bukan string —
         * pelajaran 24 Sep: jangan percaya bentuk field payload WAHA.
         */
        $id = '';

        foreach (['id._serialized', '_data.id._serialized', 'id', 'key.id', '_data.id.id'] as $jalan) {
            $id = WahaCerminService::teks(data_get($res['data'], $jalan));

            if ($id !== '') {
                break;
            }
        }

        /*
         * Id disimpan dengan awalan 'waha:' — sama seperti yang dipasang
         * cermin pada pesan masuk. Kolomnya unik SE-TABEL (bukan per kanal),
         * jadi tanpa awalan sebuah id WAHA bisa bertabrakan dengan wamid
         * jalur resmi; dan yang lebih penting, webhook 'message.ack' yang
         * datang kemudian mencari barisnya dengan awalan itu — tanpa dipasang
         * di sini, centang pesan yang KITA kirim tak pernah bergerak.
         */
        return [
            'success'     => true,
            'message_id'  => $id !== '' ? 'waha:' . $id : null,
            'customer_id' => null,
            'raw'         => (array) $res['data'],
            'error'       => null,
        ];
    }

    private function takAda(string $apa): string
    {
        return $apa . ' tidak ada di jalur WhatsApp self-host — itu milik jalur resmi (Meta).';
    }

    /** @return array{success:bool, message_id:null, customer_id:null, raw:array, error:string} */
    private function gagal(string $pesan): array
    {
        return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => $pesan];
    }
}
