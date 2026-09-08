<?php

namespace App\Modules\CRM\Providers;

use App\Models\CrmSetting;
use App\Modules\CRM\Contracts\NotificationProvider;
use App\Modules\CRM\Support\PhoneNumber;
use App\Modules\CRM\Support\TemplateResmi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter WAHA (WhatsApp HTTP API) yang di-self-host di server on-prem.
 *
 * Ini jalur TIDAK RESMI: WAHA menautkan diri sebagai perangkat ke sebuah akun
 * WhatsApp biasa, persis seperti WhatsApp Web. Konsekuensinya melekat pada
 * seluruh berkas ini:
 *  - tidak ada template, tidak ada jendela 24 jam — yang dikirim teks biasa;
 *  - kalimatnya WAJIB dirangkai dari TemplateResmi, supaya bunyinya sama
 *    persis dengan yang disetujui Meta di jalur berbayar;
 *  - hanya untuk pesan TRANSAKSIONAL. Promosi/blast lewat jalur ini adalah
 *    cara tercepat membuat nomornya diblokir permanen;
 *  - akunnya milik HP pemegang SIM, bukan milik server. Sesi bisa putus
 *    (HP mati, WhatsApp keluarkan perangkat tertaut) — karena itu jawaban
 *    'tahan' ada, dan bukan sekadar 'gagal'.
 *
 * ENGINE TERKUNCI DI NOWEB. Bentuk payload webhook & endpoint WAHA berbeda
 * antar-engine; menggantinya ke WEBJS berarti membongkar adapter ini, dan
 * WEBJS memakai 3–4x memori (Chromium) di server yang sudah menampung ERP +
 * MariaDB.
 *
 * ⚠️ Alamatnya WAJIB 127.0.0.1. API key WAHA = kunci penuh sebuah akun
 * WhatsApp, dan instance WAHA terbuka rutin dipindai bot. Jangan pernah
 * menyambungkannya ke Cloudflare Tunnel — ERP & WAHA satu server.
 */
class WahaProvider implements NotificationProvider
{
    private CrmSetting $setting;

    public function __construct(?CrmSetting $setting = null)
    {
        $this->setting = $setting ?: CrmSetting::for('waha');
    }

    public function key(): string
    {
        return 'waha';
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    /** Nama sesi WAHA yang dipakai mengirim (nomor aktif). */
    public function sesi(): string
    {
        return (string) (($this->setting->config['session'] ?? null)
            ?: config('crm.notifikasi.waha.session', 'notifikasi'));
    }

    public function kirimNotifikasi(array $payload): array
    {
        if (! $this->isReady()) {
            // Belum dikonfigurasi = keadaan sementara juga (tinggal diisi di
            // layar Pengaturan), jadi ditahan — bukan dihanguskan.
            return $this->tahan('Jalur WAHA belum dikonfigurasi.');
        }

        $to = PhoneNumber::normalize($payload['to'] ?? null);

        if (! $to) {
            return $this->gagal('Nomor tujuan tidak valid.');
        }

        $allowed = (array) config('crm.allowed_recipients', []);

        if ($allowed && ! in_array($to, $allowed, true)) {
            return $this->gagal("Nomor {$to} tidak ada di daftar putih penerima (config crm.allowed_recipients).");
        }

        $teks = TemplateResmi::render(
            (string) ($payload['template'] ?? ''),
            (array) ($payload['body'] ?? []),
            $payload['url_lacak'] ?? null
        );

        if (! $teks) {
            // Bukan keadaan sementara: nama templatenya memang tak dikenal.
            // Ditandai gagal supaya kelihatan, bukan mengendap di antrean.
            return $this->gagal('Template tidak dikenal: ' . ($payload['template'] ?? '(kosong)'));
        }

        /*
         * Status sesi diperiksa DULU, sebelum mengirim. Tanpa ini, pesan yang
         * dikirim saat sesi mati ditolak WAHA dengan galat yang bentuknya
         * berubah-ubah dan mudah salah dibaca sebagai kegagalan permanen.
         */
        $status = $this->statusJalur();

        if (! $status['siap']) {
            return $this->tahan('Sesi WhatsApp "' . $this->sesi() . '" berstatus ' . $status['status'] . '.');
        }

        $res = $this->request('post', '/api/sendText', [
            'session' => $this->sesi(),
            'chatId'  => $to . '@c.us',
            'text'    => $teks,
            // Kartu pratinjau link harus diminta; tanpa ini URL lacak datang polos.
            'linkPreview' => str_contains($teks, 'http'),
        ]);

        if (! $res['success']) {
            /*
             * Tak terjangkau = container mati / sedang restart. Itu keadaan
             * sementara, dan justru saat itulah antrean paling perlu utuh.
             * Penolakan BERISI jawaban dari WAHA barulah kegagalan sungguhan.
             */
            return $res['terjangkau']
                ? $this->gagal((string) $res['error'])
                : $this->tahan((string) $res['error']);
        }

        $id = data_get($res['data'], 'id') ?? data_get($res['data'], '_data.id.id') ?? data_get($res['data'], 'key.id');

        return ['success' => true, 'message_id' => $id ? (string) $id : null, 'tahan' => false, 'error' => null];
    }

    /**
     * Status sesi WAHA. 'WORKING' = tertaut & siap; sisanya (STARTING,
     * SCAN_QR_CODE, STOPPED, FAILED) berarti belum bisa mengirim.
     */
    public function statusJalur(): array
    {
        if (! $this->isReady()) {
            return ['siap' => false, 'status' => 'BELUM_DIATUR', 'keterangan' => 'Jalur WAHA belum dikonfigurasi.'];
        }

        $res = $this->request('get', '/api/sessions/' . rawurlencode($this->sesi()));

        if (! $res['success']) {
            return ['siap' => false, 'status' => 'TAK_TERJANGKAU', 'keterangan' => (string) $res['error']];
        }

        $status = strtoupper((string) (data_get($res['data'], 'status') ?: 'TIDAK_DIKETAHUI'));

        return [
            'siap'       => $status === 'WORKING',
            'status'     => $status,
            'keterangan' => $status === 'WORKING' ? null : 'Sesi belum siap mengirim.',
        ];
    }

    /**
     * @return array{success:bool, data:array, terjangkau:bool, error:?string}
     *
     * 'terjangkau' memisahkan dua kegagalan yang tampak mirip tapi berbeda
     * penanganannya: WAHA menjawab-tapi-menolak (permanen) vs WAHA tak
     * menjawab sama sekali (sementara).
     */
    private function request(string $method, string $path, array $body = []): array
    {
        try {
            $req = Http::withHeaders(['X-Api-Key' => (string) $this->setting->api_key])
                ->acceptJson()
                ->timeout((int) config('crm.notifikasi.waha.timeout', 20));

            $url = $this->setting->effectiveBaseUrl() . $path;
            $res = $method === 'get' ? $req->get($url) : $req->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('[CRM] WAHA tidak terjangkau', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'data' => [], 'terjangkau' => false, 'error' => $e->getMessage()];
        }

        $json = (array) ($res->json() ?? []);

        if ($res->failed()) {
            $error = data_get($json, 'message') ?? data_get($json, 'error') ?? 'HTTP ' . $res->status();

            Log::warning('[CRM] WAHA menolak permintaan', [
                'path'    => $path,
                'status'  => $res->status(),
                'jawaban' => mb_substr($res->body(), 0, 1000),
            ]);

            /*
             * 5xx & 429 = WAHA hidup tapi sedang tak sanggup (engine baru
             * bangun, antrean penuh). Itu sementara — ditahan, bukan hangus.
             */
            $sementara = $res->status() >= 500 || $res->status() === 429;

            return ['success' => false, 'data' => $json, 'terjangkau' => ! $sementara, 'error' => (string) $error];
        }

        return ['success' => true, 'data' => $json, 'terjangkau' => true, 'error' => null];
    }

    private function gagal(string $pesan): array
    {
        return ['success' => false, 'message_id' => null, 'tahan' => false, 'error' => $pesan];
    }

    private function tahan(string $pesan): array
    {
        return ['success' => false, 'message_id' => null, 'tahan' => true, 'error' => $pesan];
    }
}
