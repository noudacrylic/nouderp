<?php

namespace App\Modules\Sales\Services\Payment;

use App\Models\PaymentSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * QRISLY (Komerce/RajaOngkir) — QRIS statis milik sendiri → QRIS dinamis per pesanan.
 *
 * PENTING soal biaya: Komerce menagih **Rp100 per QRIS dinamis yang di-generate**,
 * BUKAN per pembayaran berhasil. Karena itu generate() hanya boleh dipanggil dari
 * WebPaymentService::ensureQris() yang menyimpan & memakai ulang QR selama belum
 * kedaluwarsa. JANGAN panggil dari halaman yang bisa di-refresh atau dari polling.
 *
 * Deteksi pembayaran berjalan lewat Mobile App Listener di HP yang sama dengan
 * aplikasi merchant QRIS (BCA) → Komerce → webhook/polling ke ERP. Artinya jalur
 * otomatis bisa mati kalau HP mati: eskalasi Telegram tetap jadi pengaman.
 *
 * Dokumentasi: rajaongkir.com/docs/qrisly/*  (header X-API-Key)
 */
class QrislyProvider
{
    // Akhiran /user WAJIB — tanpa itu nginx menjawab 404 HTML (bukan JSON).
    // Diverifikasi 23 Jul 2026: POST tanpa payload → sandbox 400 "Invalid request
    // payload" (key diterima), production 401 (key sandbox ditolak) — jadi kedua
    // host benar dan header X-API-Key sudah tepat.
    public const BASE_SANDBOX    = 'https://api-sandbox.collaborator.komerce.id/user';
    public const BASE_PRODUCTION = 'https://api.collaborator.komerce.id/user';

    public function __construct(private ?PaymentSetting $setting = null)
    {
        $this->setting = $setting ?: PaymentSetting::singleton();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->setting->conf('qris_enabled', false)
            && $this->apiKey() !== ''
            && $this->qrisId() !== '';
    }

    public function apiKey(): string
    {
        return trim((string) $this->setting->conf('qris_api_key', ''));
    }

    /** ID QRIS statis hasil upload sekali di awal. */
    public function qrisId(): string
    {
        return trim((string) $this->setting->conf('qris_id', ''));
    }

    public function baseUrl(): string
    {
        return $this->setting->conf('qris_env') === 'production'
            ? self::BASE_PRODUCTION
            : self::BASE_SANDBOX;
    }

    private function http(): PendingRequest
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new RuntimeException('API key QRISLY belum diisi (Settings → Integrasi → QRIS).');
        }

        return Http::withHeaders(['X-API-Key' => $key])
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Unggah QRIS statis (sekali saja) → dapat qris_id untuk dipakai berulang.
     * @param string $absolutePath berkas gambar QRIS (PNG/JPG)
     */
    public function uploadQris(string $absolutePath, string $name): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("Berkas QRIS tidak ditemukan: {$absolutePath}");
        }

        // MIME WAJIB eksplisit: tanpa ini Guzzle mengirim application/octet-stream dan
        // QRISLY menolak ("File type not allowed, harus image/png|jpeg|jpg").
        $mime = function_exists('mime_content_type') ? (mime_content_type($absolutePath) ?: 'image/png') : 'image/png';
        $ext  = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) ?: 'png';

        $res = $this->http()
            ->attach('qris_image', file_get_contents($absolutePath), 'qris.' . $ext, ['Content-Type' => $mime])
            ->post($this->baseUrl() . '/api/v1/qrisly/upload-qris', ['name' => $name]);

        return $this->unwrap($res->json(), $res->successful(), 'upload-qris');
    }

    /**
     * Buat QRIS dinamis bernominal. BERBAYAR Rp100 per panggilan — lihat catatan kelas.
     * @return array{history_id:?string, qr_string:?string, expired_at:?string, amount:?float, raw:array}
     */
    public function generate(float $amount, ?string $reference = null): array
    {
        // qris_id WAJIB integer — dikirim sebagai string ditolak
        // ("cannot unmarshal string into Go struct field GenerateQrislyRequest").
        $payload = [
            'qris_id'     => (int) $this->qrisId(),
            'amount'      => (int) round($amount),
            'output_type' => 'string',
        ];
        if ($reference) {
            $payload['reference'] = $reference; // field asing diabaikan API, aman
        }

        $res  = $this->http()->post($this->baseUrl() . '/api/v1/qrisly/generate-qris', $payload);
        $data = $this->unwrap($res->json(), $res->successful(), 'generate-qris');

        // ⚠️ QRISLY menambahkan selisih uniknya sendiri: minta 79.900 → QR jadi 79.903
        // (`original_amount` vs `final_amount`). Selisih itulah penanda pencocokan
        // pembayaran mereka, jadi PEMBELI MEMBAYAR final_amount — bukan nominal kita.
        $final    = $this->pick($data, ['final_amount', 'amount', 'total']);
        $original = $this->pick($data, ['original_amount']);

        return [
            'history_id'      => $this->pick($data, ['history_id', 'id', 'transaction_id']),
            'qr_string'       => $this->pick($data, ['qris_string', 'qr_string', 'qris', 'string', 'qr']),
            // Masa berlaku dikirim sebagai `expiry_time` waktu lokal (Asia/Jakarta), ±15 menit.
            'expired_at'      => $this->pick($data, ['expiry_time', 'expired_at', 'expires_at', 'expired_date']),
            'amount'          => $final !== null ? (float) $final : null,
            'original_amount' => $original !== null ? (float) $original : null,
            'raw'             => $data,
        ];
    }

    /**
     * Status pembayaran satu QRIS dinamis.
     * @return array{paid:bool, status:?string, amount:?float, paid_at:?string, raw:array}
     */
    public function status(string $historyId): array
    {
        $res  = $this->http()->get($this->baseUrl() . '/api/v1/qrisly/payment-status/' . urlencode($historyId));
        $data = $this->unwrap($res->json(), $res->successful(), 'payment-status');

        $status = strtolower((string) ($this->pick($data, ['status', 'payment_status', 'transaction_status']) ?? ''));

        return [
            'paid'    => $this->looksPaid($status),
            'status'  => $status ?: null,
            'amount'  => ($v = $this->pick($data, ['amount', 'final_amount', 'total'])) !== null ? (float) $v : null,
            'paid_at' => $this->pick($data, ['paid_at', 'payment_date', 'updated_at']),
            'raw'     => $data,
        ];
    }

    /** Istilah "lunas" bisa berbeda antar versi API — kenali beberapa varian. */
    public function looksPaid(?string $status): bool
    {
        return in_array(strtolower((string) $status), ['paid', 'success', 'settled', 'completed', 'sukses', 'berhasil'], true);
    }

    /** Buka amplop respons Komerce (biasanya {meta, data}) + lempar bila gagal. */
    private function unwrap(?array $json, bool $ok, string $context): array
    {
        if (! $ok) {
            $msg    = $json['meta']['message'] ?? $json['message'] ?? 'Permintaan ditolak';
            $detail = is_string($json['data'] ?? null) ? $json['data'] : null;
            Log::warning("QRISLY {$context} gagal", ['response' => $json]);

            // Terjemahkan kegagalan yang paling sering terjadi agar bisa ditindak admin.
            $human = match (true) {
                str_contains(strtolower($msg), 'insufficient balance') =>
                    'Saldo Komerce habis. Isi ulang saldo di dashboard Komerce → Finance; '
                    . 'tiap pembuatan QRIS dinamis memotong Rp100 dari saldo prabayar.',
                str_contains(strtolower($msg), 'unauthorized') =>
                    'API key ditolak. Pastikan key cocok dengan lingkungan (Sandbox vs Production).',
                str_contains(strtolower($msg), 'recognize qr') =>
                    'Gambar yang diunggah tidak terbaca sebagai QRIS. Pakai tangkapan QRIS statis yang jelas & tidak terpotong.',
                default => $msg . ($detail ? " ({$detail})" : ''),
            };

            throw new RuntimeException($human);
        }

        $data = $json['data'] ?? $json ?? [];
        return is_array($data) ? $data : [];
    }

    /** Ambil nilai pertama yang ada dari beberapa kemungkinan nama field. */
    private function pick(array $data, array $keys)
    {
        foreach ($keys as $k) {
            $v = data_get($data, $k);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return null;
    }
}
