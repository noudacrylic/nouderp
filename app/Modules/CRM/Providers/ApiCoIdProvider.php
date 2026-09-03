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
 *  - 'media_url' menerima URL publik MAUPUN media_id hasil POST /media/upload.
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

        $body = array_filter([
            'phone_number'        => $to,
            'channel'             => $payload['channel'] ?? 'whatsapp',
            'message_type'        => 'text',
            'content'             => (string) ($payload['text'] ?? ''),
            'reply_to_message_id' => $payload['reply_to'] ?? null,
        ], fn ($v) => $v !== null);

        return $this->send($body, $payload);
    }

    public function sendMedia(array $payload): array
    {
        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if ($blocked = $this->rejectRecipient($to)) {
            return $blocked;
        }

        $body = array_filter([
            'phone_number' => $to,
            'channel'      => $payload['channel'] ?? 'whatsapp',
            'message_type' => $payload['type'] ?? 'image',
            'media_url'    => $payload['media'] ?? null,
            'caption'      => $payload['caption'] ?? null,
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

    private function post(string $path, array $body): array
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

            Log::warning('[CRM] api.co.id menolak permintaan', ['path' => $path, 'status' => $res->status(), 'error' => $error]);

            return ['success' => false, 'data' => $json, 'error' => (string) $error];
        }

        return ['success' => true, 'data' => $json, 'error' => null];
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message_id' => null, 'customer_id' => null, 'raw' => [], 'error' => $message];
    }
}
