<?php

namespace App\Modules\CRM\Providers;

use App\Models\CrmSetting;
use App\Modules\CRM\Contracts\ChatProvider;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter api.co.id Chat Gateway.
 * Kredensial dari crm_settings (provider='apicoid'). Docs: https://chat.api.co.id
 *
 * Catatan bentuk payload yang mudah salah (sudah ditangani di sini):
 *  - Nomor pada pengiriman satuan TANPA '+' ('628…'), sedangkan endpoint
 *    broadcast justru mewajibkan '+'. Broadcast SENGAJA tidak diimplementasi —
 *    notifikasi kita per-pesanan, dan tidak mengimplementasinya berarti tidak
 *    bisa salah memakainya.
 *  - Variabel template dikirim sebagai ARRAY components gaya Meta
 *    (components[].parameters[]), bukan objek datar {"1": "..."} — bentuk datar
 *    itu hanya milik broadcast.
 *  - 'media_url' HANYA untuk URL publik. Id hasil POST /media/upload dikirim
 *    lewat kolom terpisah 'media_id' — dokumentasi vendor keliru soal ini,
 *    dan salahnya baru terlihat saat kiriman nyata ditolak 'Invalid URL'.
 */
class ApiCoIdProvider implements ChatProvider
{
    private CrmSetting $setting;

    public function __construct(?CrmSetting $setting = null)
    {
        $this->setting = $setting ?: CrmSetting::for('apicoid');
    }

    public function key(): string
    {
        return 'apicoid';
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    public function phoneNumbers(): array
    {
        $res = $this->get('/phone-numbers');

        if (! $res['success']) {
            return ['success' => false, 'numbers' => [], 'error' => $res['error']];
        }

        $numbers = array_map(fn (array $row) => [
            'id'              => (string) ($row['id'] ?? ''),
            'phone_number_id' => $row['phone_number_id'] ?? null,
            'display'         => $row['display_phone_number'] ?? null,
            'verified_name'   => $row['verified_name'] ?? null,
            'quality_rating'  => $row['quality_rating'] ?? null,
            'is_primary'      => (bool) ($row['is_primary'] ?? false),
            'waba_id'         => $row['waba_id'] ?? null,
        ], (array) ($res['data']['data'] ?? []));

        return ['success' => true, 'numbers' => $numbers, 'error' => null];
    }

    public function sendText(array $payload): array
    {
        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if ($blocked = $this->rejectRecipient($to)) {
            return $blocked;
        }

        $teks = (string) ($payload['text'] ?? '');

        $body = array_filter([
            'phone_number'        => $to,
            'channel'             => $payload['channel'] ?? 'whatsapp',
            'message_type'        => 'text',
            'content'             => $teks,
            'reply_to_message_id' => $payload['reply_to'] ?? null,
            /*
             * Kartu pratinjau link (gambar + judul + deskripsi) TIDAK muncul
             * sendiri lewat API — beda dari mengetik di HP, di mana aplikasi
             * WhatsApp yang mengambil tag Open Graph halamannya. Lewat API
             * penandanya harus ikut dikirim, kalau tidak linknya datang polos.
             *
             * Isinya diambil WhatsApp dari halaman storefront, jadi kirim-link
             * TIDAK perlu unggah media sama sekali — dan itu menghindarkan kita
             * dari 'media_id' vendor yang kedaluwarsa 30 hari.
             */
            'preview_url'         => self::mengandungTautan($teks) ?: null,
        ], fn ($v) => $v !== null);

        return $this->send($body, $payload);
    }

    public function sendInteraktif(array $payload): array
    {
        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if ($blocked = $this->rejectRecipient($to)) {
            return $blocked;
        }

        return $this->send([
            'phone_number' => $to,
            'channel'      => $payload['channel'] ?? 'whatsapp',
            'message_type' => 'interactive',
            'interactive'  => self::buildInteraktif(
                (string) ($payload['text'] ?? ''),
                (array) ($payload['buttons'] ?? [])
            ),
        ], $payload);
    }

    /**
     * Susun objek `interactive` gaya Meta. Static murni supaya bentuknya bisa
     * diuji tanpa jaringan — sama alasannya dengan buildTemplateComponents().
     *
     * ⚠️ BELUM PERNAH DIUJI KE VENDOR SUNGGUHAN. Bentuknya mengikuti Meta
     * karena di situlah taruhan terbaiknya: `template` pun diteruskan vendor
     * apa adanya dalam bentuk Meta (components sebagai array). Kalau ternyata
     * vendor menuntut bentuk lain, YANG BERUBAH CUKUP METHOD INI.
     *
     * Batas Meta yang ditegakkan di sini, bukan diserahkan ke API: maksimal 3
     * tombol, judul maksimal 20 karakter. Dipotong diam-diam memang tidak
     * ideal, tapi jauh lebih baik daripada seluruh pesan ditolak dengan galat
     * yang tak menyebut tombol mana yang kepanjangan.
     */
    public static function buildInteraktif(string $teks, array $tombol): array
    {
        return [
            'type'   => 'button',
            'body'   => ['text' => $teks],
            'action' => [
                'buttons' => array_values(array_map(fn (array $t) => [
                    'type'  => 'reply',
                    'reply' => [
                        'id'    => (string) ($t['id'] ?? 'balas'),
                        'title' => mb_substr((string) ($t['title'] ?? ''), 0, 20),
                    ],
                ], array_slice($tombol, 0, 3))),
            ],
        ];
    }

    /** Ada URL di dalam teks? Penanda pratinjau hanya disertakan bila ada. */
    private static function mengandungTautan(string $teks): bool
    {
        return (bool) preg_match('~https?://~i', $teks);
    }

    public function sendMedia(array $payload): array
    {
        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if ($blocked = $this->rejectRecipient($to)) {
            return $blocked;
        }

        $media = $payload['media'] ?? null;

        /*
         * `media_url` HANYA menerima URL sungguhan; id hasil unggah punya
         * kolomnya sendiri (`media_id`). DIBUKTIKAN 5 Sep 2026 lewat kiriman
         * nyata: unggahan berhasil, lalu /messages/send menolak dengan
         * {"field":"media_url","message":"Invalid URL"}. Dokumentasi vendor
         * yang menyebut media_url menerima keduanya KELIRU — jangan
         * disederhanakan kembali jadi satu kolom.
         */
        $urlPenuh = is_string($media) && preg_match('~^https?://~i', $media);

        $body = array_filter([
            'phone_number' => $to,
            'channel'      => $payload['channel'] ?? 'whatsapp',
            'message_type' => $payload['type'] ?? 'image',
            'media_url'    => $urlPenuh ? $media : null,
            'media_id'     => $urlPenuh ? null : $media,
            'caption'      => $payload['caption'] ?? null,
            // Dokumen tanpa nama sampai ke pelanggan sebagai berkas tak bernama;
            // untuk gambar/video kolomnya diabaikan vendor, jadi aman selalu diisi.
            'filename'     => $payload['nama_berkas'] ?? null,
        ], fn ($v) => $v !== null);

        return $this->send($body, $payload);
    }

    public function sendTemplate(array $payload): array
    {
        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if ($blocked = $this->rejectRecipient($to)) {
            return $blocked;
        }

        $body = [
            'phone_number' => $to,
            'channel'      => 'whatsapp',
            'message_type' => 'template',
            'template'     => [
                'name'       => (string) ($payload['template'] ?? ''),
                'language'   => ['code' => $payload['language'] ?? 'id'],
                'components' => self::buildTemplateComponents($payload),
            ],
        ];

        return $this->send($body, $payload);
    }

    /**
     * Susun components template gaya Meta. Dipisah sebagai static murni supaya
     * bentuknya bisa diuji tanpa jaringan sama sekali — inilah bagian yang
     * paling mahal kalau salah (template ditolak / variabel tertukar).
     */
    public static function buildTemplateComponents(array $payload): array
    {
        $components = [];

        if ($header = $payload['header_media'] ?? null) {
            $type = $header['type'] ?? 'image';
            $components[] = [
                'type'       => 'header',
                'parameters' => [[
                    'type' => $type,
                    $type  => ['link' => $header['link'] ?? ''],
                ]],
            ];
        }

        $bodyVars = array_values((array) ($payload['body'] ?? []));

        if ($bodyVars) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    $bodyVars
                ),
            ];
        }

        // Tombol URL dinamis: yang dikirim HANYA potongan akhir URL, bukan URL
        // penuh — sisanya sudah tertanam di template saat disetujui Meta.
        if (($suffix = $payload['url_button_suffix'] ?? null) !== null && $suffix !== '') {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => 0,
                'parameters' => [['type' => 'text', 'text' => (string) $suffix]],
            ];
        }

        return $components;
    }

    public function webhooks(): array
    {
        $res = $this->get('/webhooks');

        if (! $res['success']) {
            return ['success' => false, 'endpoints' => [], 'error' => $res['error']];
        }

        $endpoints = array_map(fn (array $row) => [
            'id'             => (string) ($row['id'] ?? ''),
            'url'            => $row['url'] ?? null,
            'is_active'      => (bool) ($row['is_active'] ?? false),
            'failure_count'  => (int) ($row['failure_count'] ?? 0),
            'disabled_at'    => $row['disabled_at'] ?? null,
            'disable_reason' => $row['disable_reason'] ?? null,
            'events'         => (array) ($row['events'] ?? []),
        ], (array) ($res['data']['data'] ?? []));

        return ['success' => true, 'endpoints' => $endpoints, 'error' => null];
    }

    public function enableWebhook(string $id): array
    {
        $res = $this->post('/webhooks/' . rawurlencode($id) . '/enable', []);

        return ['success' => $res['success'], 'error' => $res['error']];
    }

    public function uploadMedia(string $absolutePath, string $mime, string $filename): array
    {
        if (! $this->isReady()) {
            return ['success' => false, 'media_id' => null, 'error' => 'Provider chat api.co.id belum dikonfigurasi.'];
        }

        if (! is_readable($absolutePath)) {
            return ['success' => false, 'media_id' => null, 'error' => 'Berkas tidak terbaca: ' . $filename];
        }

        try {
            $res = Http::withToken($this->setting->api_key)
                ->acceptJson()
                ->timeout(60)
                ->attach('file', file_get_contents($absolutePath), $filename, ['Content-Type' => $mime])
                ->post($this->setting->effectiveBaseUrl() . '/media/upload');
        } catch (\Throwable $e) {
            Log::warning('[CRM] unggah media gagal', ['file' => $filename, 'error' => $e->getMessage()]);

            return ['success' => false, 'media_id' => null, 'error' => $e->getMessage()];
        }

        $json = (array) ($res->json() ?? []);

        if ($res->failed() || ($json['success'] ?? false) !== true) {
            $error = data_get($json, 'error.message') ?? data_get($json, 'message') ?? 'HTTP ' . $res->status();

            Log::warning('[CRM] unggah media ditolak', [
                'status'  => $res->status(),
                'jawaban' => mb_substr($res->body(), 0, 1500),
                'berkas'  => $filename,
                'mime'    => $mime,
            ]);

            return ['success' => false, 'media_id' => null, 'error' => (string) $error];
        }

        // Bentuk jawaban unggah belum pernah diadu dengan kiriman sungguhan;
        // sekali dicatat, alias yang benar bisa dipastikan lalu sisanya dibuang.
        Log::info('[CRM] unggah media berhasil', ['jawaban' => mb_substr($res->body(), 0, 800)]);

        // ⚠️ media_id vendor kedaluwarsa 30 hari — cukup untuk mengirim SEKARANG,
        // tidak boleh dijadikan satu-satunya salinan. Berkasnya tetap kita simpan.
        $id = data_get($json, 'data.media_id') ?? data_get($json, 'data.id') ?? data_get($json, 'media_id');

        return $id
            ? ['success' => true, 'media_id' => (string) $id, 'error' => null]
            : ['success' => false, 'media_id' => null, 'error' => 'Vendor tidak mengembalikan media_id.'];
    }

    public function templates(): array
    {
        $numberId = $this->setting->default_phone_number_id;

        $res = $this->get('/templates' . ($numberId ? '?whatsapp_phone_number_id=' . urlencode($numberId) : ''));

        if (! $res['success']) {
            return ['success' => false, 'templates' => [], 'error' => $res['error']];
        }

        $daftar = array_map(function (array $row) {
            $body = self::bodyTemplate($row);

            return [
                'id'        => (string) ($row['id'] ?? ''),
                // Dua bentuk respons beredar: dokumentasi menyebut
                // `template_name`, sebagian jawaban memakai `name`. Yang dibaca
                // KEDUANYA — kalau cuma satu, daftar template di layar tampil
                // kosong tanpa satu pun pesan galat, dan tak ada yang curiga.
                'name'      => (string) ($row['template_name'] ?? $row['name'] ?? ''),
                'language'  => (string) ($row['language'] ?? 'id'),
                'category'  => $row['category'] ?? null,
                'status'    => strtoupper((string) ($row['status'] ?? 'UNKNOWN')),
                'body'      => $body,
                'variables' => self::hitungVariabel($body),
            ];
        }, (array) ($res['data']['data'] ?? []));

        return ['success' => true, 'templates' => $daftar, 'error' => null];
    }

    /** Bunyi badan template; bentuk komponennya beda-beda antar respons vendor. */
    private static function bodyTemplate(array $row): ?string
    {
        // `content` (bentuk yang didokumentasikan), `body`, lalu components[].
        if (is_string($row['content'] ?? null)) {
            return $row['content'];
        }

        if (is_string($row['body'] ?? null)) {
            return $row['body'];
        }

        foreach ((array) ($row['components'] ?? []) as $komponen) {
            if (strtoupper((string) ($komponen['type'] ?? '')) === 'BODY') {
                return $komponen['text'] ?? null;
            }
        }

        return null;
    }

    /**
     * Jumlah variabel = angka {{n}} TERTINGGI, bukan berapa kali ia muncul.
     * Template yang menyebut {{1}} dua kali tetap minta satu isian; menghitung
     * kemunculan akan meminta isian hantu yang ditolak Meta.
     */
    private static function hitungVariabel(?string $body): int
    {
        if (! $body || ! preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) {
            return 0;
        }

        return max(array_map('intval', $m[1]));
    }

    /**
     * Buat template BARU lalu langsung ajukan ke Meta.
     *
     * Vendor memisahkannya jadi dua langkah: `POST /templates` cuma menyimpan
     * catatan di sisi mereka, dan baru `POST /templates/{id}/submit` yang
     * mengirimkannya ke Meta. Keduanya disatukan di sini justru karena
     * pemisahan itu adalah jebakan: melewatkan langkah kedua menghasilkan
     * template yang tampak "sudah dibuat", berstatus PENDING selamanya, dan
     * tak pernah sampai ke Meta — kegagalan yang tidak menimbulkan gejala apa
     * pun sampai ada yang bertanya kenapa template itu belum disetujui juga.
     *
     * Kalau pembuatannya berhasil tapi pengajuannya gagal, itu DIKATAKAN
     * apa adanya berikut id-nya, supaya bisa diajukan ulang tanpa membuat
     * duplikat (nama template unik per bahasa — percobaan kedua akan ditolak).
     *
     * @param  string[]  $variabel  contoh nilai tiap {{n}}, berurutan
     * @return array{success:bool, id:?string, status:?string, error:?string}
     */
    public function buatTemplate(string $nama, string $kategori, string $body, array $variabel = [], string $bahasa = 'id', array $tombol = []): array
    {
        $muatan = [
            'template_name' => $nama,
            'category'      => strtoupper($kategori),
            'language'      => $bahasa,
            'body'          => $body,
        ];

        if ($variabel) {
            $muatan['variables'] = array_values(array_map('strval', $variabel));
        }

        if ($tombol) {
            $muatan['buttons'] = array_values($tombol);
        }

        if ($id = $this->setting->default_phone_number_id) {
            $muatan['whatsapp_phone_number_id'] = $id;
        }

        $buat = $this->post('/templates', $muatan);

        if (! $buat['success']) {
            return ['success' => false, 'id' => null, 'status' => null, 'error' => $buat['error']];
        }

        $idTemplate = (string) (data_get($buat['data'], 'data.id') ?? data_get($buat['data'], 'id') ?? '');

        if ($idTemplate === '') {
            return [
                'success' => false,
                'id'      => null,
                'status'  => null,
                'error'   => 'Vendor tidak mengembalikan id template, jadi pengajuannya tidak bisa dilanjutkan.',
            ];
        }

        $ajukan = $this->post('/templates/' . rawurlencode($idTemplate) . '/submit');

        if (! $ajukan['success']) {
            return [
                'success' => false,
                'id'      => $idTemplate,
                'status'  => 'PENDING',
                'error'   => 'Template tersimpan (id ' . $idTemplate . ') tapi GAGAL diajukan ke Meta: '
                    . ($ajukan['error'] ?: 'tanpa keterangan')
                    . '. Ajukan ulang dari dasbor vendor — jangan dibuat ulang, namanya sudah terpakai.',
            ];
        }

        return [
            'success' => true,
            'id'      => $idTemplate,
            'status'  => (string) (data_get($ajukan['data'], 'data.status') ?? 'PENDING'),
            'error'   => null,
        ];
    }

    public function windowStatus(string $identifier): array
    {
        $id  = PhoneNumber::normalize($identifier) ?: $identifier;
        $res = $this->get('/customers/' . rawurlencode($id) . '/window-status');

        if (! $res['success']) {
            return ['success' => false, 'is_open' => false, 'expires_at' => null, 'raw' => [], 'error' => $res['error']];
        }

        $wa = (array) ($res['data']['data']['whatsapp'] ?? []);

        return [
            'success'    => true,
            'is_open'    => (bool) ($wa['is_window_active'] ?? false),
            'expires_at' => $wa['window_expires_at'] ?? null,
            'raw'        => $res['data'],
            'error'      => null,
        ];
    }

    /**
     * GET /customers/{nomor} — DIVERIFIKASI 11 Sep 2026: jawabannya membawa
     * `data.name` ("Ferina mei"), nama yang sama dengan yang tampil di OneInbox.
     * Nomor dipakai sebagai kunci, bukan customer_id vendor, karena nomor
     * selalu kita punya sedangkan customer_id tidak.
     */
    public function profilKontak(string $identifier): array
    {
        $id  = PhoneNumber::normalize($identifier) ?: $identifier;
        $res = $this->get('/customers/' . rawurlencode($id));

        if (! $res['success']) {
            return ['success' => false, 'name' => null, 'error' => $res['error']];
        }

        $nama = trim((string) data_get($res['data'], 'data.name', ''));

        return ['success' => true, 'name' => $nama !== '' ? $nama : null, 'error' => null];
    }

    /**
     * Tolak penerima di luar daftar putih SEBELUM menyentuh jaringan.
     * Daftar kosong = penjaga mati (mode produksi penuh).
     */
    private function rejectRecipient(?string $to): ?array
    {
        if (! $to) {
            return $this->fail('Nomor tujuan tidak valid.');
        }

        $allowed = (array) config('crm.allowed_recipients', []);

        if ($allowed && ! in_array($to, $allowed, true)) {
            return $this->fail("Nomor {$to} tidak ada di daftar putih penerima (config crm.allowed_recipients).");
        }

        return null;
    }

    /** Kirim satu pesan; menyisipkan whatsapp_phone_number_id bila ada. */
    private function send(array $body, array $payload): array
    {
        $numberId = $payload['phone_number_id'] ?? $this->setting->default_phone_number_id;

        if ($numberId) {
            $body['whatsapp_phone_number_id'] = $numberId;
        }

        $res = $this->post('/messages/send', $body);

        if (! $res['success']) {
            return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => $res['error']];
        }

        $data = (array) ($res['data']['data'] ?? []);

        return [
            'success'     => true,
            'message_id'  => $data['message_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'raw'         => $res['data'],
            'error'       => null,
        ];
    }

    private function get(string $path): array
    {
        return $this->request('get', $path);
    }

    private function post(string $path, array $body = []): array
    {
        return $this->request('post', $path, $body);
    }

    /** @return array{success:bool, data:array, error:?string} */
    private function request(string $method, string $path, array $body = []): array
    {
        if (! $this->isReady()) {
            return ['success' => false, 'data' => [], 'error' => 'Provider chat api.co.id belum dikonfigurasi.'];
        }

        try {
            $req = Http::withToken($this->setting->api_key)
                ->acceptJson()
                ->timeout(20);

            $url = $this->setting->effectiveBaseUrl() . $path;
            $res = $method === 'get' ? $req->get($url) : $req->post($url, $body);
        } catch (\Throwable $e) {
            // Kegagalan jaringan TIDAK boleh naik ke alur bisnis pemanggil.
            Log::warning('[CRM] api.co.id tidak terjangkau', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'data' => [], 'error' => $e->getMessage()];
        }

        $json = (array) ($res->json() ?? []);

        if ($res->failed() || ($json['success'] ?? false) !== true) {
            $error = data_get($json, 'error.message')
                ?? data_get($json, 'message')
                ?? 'HTTP ' . $res->status();

            /*
             * Pesan galat vendor sering hanya "Data yang Anda masukkan tidak
             * valid" — tidak menyebut field mana. Jadi badan jawaban DAN badan
             * permintaan ikut dicatat; tanpa keduanya, memperbaiki bentuk
             * payload jadi tebak-tebakan berjam-jam.
             */
            Log::warning('[CRM] api.co.id menolak permintaan', [
                'path'       => $path,
                'status'     => $res->status(),
                'error'      => $error,
                'jawaban'    => mb_substr($res->body(), 0, 1500),
                'permintaan' => $body,
            ]);

            return ['success' => false, 'data' => $json, 'error' => (string) $error];
        }

        return ['success' => true, 'data' => $json, 'error' => null];
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => $message];
    }
}
