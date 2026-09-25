<?php

namespace App\Modules\CRM\Support;

use App\Models\CrmSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya tempat ERP berbicara HTTP dengan container WAHA.
 *
 * Dikeluarkan dari WahaProvider saat Tahap 7, waktu adapter KEDUA (chat dari
 * nomor utama) lahir. Yang dibagi bukan sekadar "kode yang mirip" melainkan
 * PENAFSIRAN jawaban WAHA — dan penafsiran itu mahal dibeli: 403 untuk kunci
 * salah (bukan 401), 404 untuk nama sesi yang tak ada, 5xx/429 yang berarti
 * "sebentar lagi coba" alih-alih "gagal". Dua salinan pasti menyimpang, dan
 * menyimpangnya tidak bergejala: yang terlihat cuma satu jalur menahan pesan
 * sementara jalur lain menghanguskannya pada keadaan yang sama persis.
 *
 * ⚠️ Alamatnya WAJIB loopback. API key WAHA = kunci penuh sebuah akun
 * WhatsApp; instance WAHA terbuka rutin dipindai bot.
 */
class WahaClient
{
    public function __construct(private CrmSetting $setting)
    {
    }

    public function setting(): CrmSetting
    {
        return $this->setting;
    }

    public function baseUrl(): string
    {
        return $this->setting->effectiveBaseUrl();
    }

    /**
     * @return array{success:bool, data:array, terjangkau:bool, kode:int, error:?string}
     *
     * 'terjangkau' memisahkan dua kegagalan yang tampak mirip tapi berbeda
     * penanganannya: WAHA menjawab-tapi-menolak (permanen) vs WAHA tak
     * menjawab sama sekali (sementara).
     */
    public function request(string $method, string $path, array $body = [], ?int $timeout = null): array
    {
        try {
            $req = Http::withHeaders(['X-Api-Key' => (string) $this->setting->api_key])
                ->acceptJson()
                ->timeout($timeout ?? (int) config('crm.waha.timeout', 20));

            $url = $this->baseUrl() . $path;

            // Ditulis sebagai match, bukan rantai ternary, supaya verb yang
            // belum didukung meledak di sini alih-alih diam-diam dikirim
            // sebagai POST ke endpoint yang tidak mengharapkannya.
            $res = match ($method) {
                'get'  => $req->get($url),
                'put'  => $req->put($url, $body),
                'post' => $req->post($url, $body),
            };
        } catch (\Throwable $e) {
            Log::warning('[CRM] WAHA tidak terjangkau', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'data' => [], 'terjangkau' => false, 'kode' => 0, 'error' => $e->getMessage()];
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

            return [
                'success'    => false,
                'data'       => $json,
                'terjangkau' => ! $sementara,
                'kode'       => $res->status(),
                'error'      => (string) (is_scalar($error) ? $error : json_encode($error)),
            ];
        }

        return ['success' => true, 'data' => $json, 'terjangkau' => true, 'kode' => $res->status(), 'error' => null];
    }

    /**
     * Status sesi bernama ini, diterjemahkan ke kosakata ERP.
     *
     * Tiga sebab kegagalan yang tampak sama di layar tapi menuntut tindakan
     * berbeda sama sekali:
     *  401/403 = kunci salah        -> perbaiki satu kolom di Pengaturan
     *  404     = nama sesi tak ada  -> samakan namanya dengan dasbor
     *  sisanya = WAHA tak menjawab  -> periksa container/terowongan
     *
     * @return array{siap:bool, status:string, keterangan:?string}
     */
    public function statusSesi(string $sesi): array
    {
        $res = $this->request('get', '/api/sessions/' . rawurlencode($sesi));

        if (! $res['success']) {
            $status = match (true) {
                in_array($res['kode'] ?? 0, [401, 403], true) => 'KUNCI_DITOLAK',
                ($res['kode'] ?? 0) === 404                   => 'SESI_TIDAK_ADA',
                default                                       => 'TAK_TERJANGKAU',
            };

            return ['siap' => false, 'status' => $status, 'keterangan' => (string) $res['error']];
        }

        $status = strtoupper((string) (data_get($res['data'], 'status') ?: 'TIDAK_DIKETAHUI'));

        return [
            'siap'       => $status === 'WORKING',
            'status'     => $status,
            'keterangan' => $status === 'WORKING' ? null : 'Sesi belum siap mengirim.',
        ];
    }
}
