<?php

namespace App\Modules\Payment\Services;

use App\Core\Accounting\Account;
use App\Models\CustomerPayment;
use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Models\PaymentFeeSetting;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CustomerPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MidtransService
{
    public function __construct(
        protected MidtransFeeCalculator $fees,
        protected PaymentLinkService $links,
        protected CustomerPaymentService $payments,
    ) {
    }

    /* ===================================================================
       Endpoint base URL & auth
       =================================================================== */

    protected ?MidtransSetting $cfg = null;

    protected function settings(): MidtransSetting
    {
        return $this->cfg ??= MidtransSetting::singleton();
    }

    protected function isProduction(): bool
    {
        return (bool) $this->settings()->is_production;
    }

    protected function snapBase(): string
    {
        return $this->isProduction()
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com';
    }

    protected function apiBase(): string
    {
        return $this->isProduction()
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    protected function serverKey(): string
    {
        // Satu sumber dengan VerifyMidtransSignature — lihat MidtransSetting::resolvedServerKey().
        $key = MidtransSetting::resolvedServerKey();
        if ($key === '') {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi di Pengaturan → Midtrans.');
        }
        return $key;
    }

    protected function authHeader(): string
    {
        return 'Basic ' . base64_encode($this->serverKey() . ':');
    }

    /* ===================================================================
       PUBLIC API
       =================================================================== */

    /**
     * Charge via Core API /v2/charge → kembalikan instruksi bayar (VA/echannel/QRIS)
     * untuk ditampilkan IN-PAGE (bukan popup Snap). order_id baru tiap charge karena
     * Midtrans menolak order_id yang sudah dipakai.
     */
    public function chargeForLink(MidtransTransaction $trx, string $channel, ?string $bank = null, ?int $amount = null): MidtransTransaction
    {
        if ($trx->sales_order_id && !$trx->sales_invoice_id) {
            // DP Sales Order: nominal (base) diisi customer, divalidasi di controller.
            $so = $trx->salesOrder;
            if (!$so) {
                throw new RuntimeException('Sales Order tidak ditemukan untuk transaksi ini.');
            }
            $base = (int) round($amount ?? 0);
            $itemId = 'SODP-' . $so->id;
            $itemName = 'DP ' . $so->order_number;
        } else {
            $invoice = $trx->invoice;
            if (!$invoice) {
                throw new RuntimeException('Invoice tidak ditemukan untuk transaksi ini.');
            }
            $base = (int) round($invoice->remaining_amount);
            $itemId = 'INV-' . $invoice->id;
            $itemName = 'Invoice ' . $invoice->invoice_number;
        }

        if ($base <= 0) {
            throw new RuntimeException('Nominal pembayaran tidak valid atau sudah lunas.');
        }

        $customerFee = $this->fees->customerCharge($base, $channel);
        $gross = $base + $customerFee;
        $orderId = $this->links->makeOrderId($trx->sales_order_id && !$trx->sales_invoice_id ? 'SODP' : 'LINK');

        $items = [
            ['id' => $itemId, 'price' => $base, 'quantity' => 1, 'name' => mb_substr($itemName, 0, 50)],
        ];
        if ($customerFee > 0) {
            $items[] = ['id' => 'ADMIN-FEE', 'price' => $customerFee, 'quantity' => 1, 'name' => 'Biaya Admin (VA/Transfer Bank)'];
        }

        $payload = array_merge(
            [
                'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $gross],
                'item_details' => $items,
                'customer_details' => $this->customerDetails($trx),
            ],
            $this->chargeMethodPayload($channel, $bank, $itemName),
        );

        $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->authHeader(),
            ])
            ->timeout(20)
            ->post($this->apiBase() . '/v2/charge', $payload);

        $data = $response->json() ?? [];
        $statusCode = (string) ($data['status_code'] ?? $response->status());

        if (!$response->successful() || !in_array($statusCode, ['200', '201'], true)) {
            Log::error('Midtrans charge failed', [
                'order_id' => $orderId,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Midtrans menolak pembayaran: ' . ($data['status_message'] ?? $response->body()));
        }

        // Nomor utama untuk audit/tampil cepat (detail lengkap di raw_response).
        $vaNumber = $data['va_numbers'][0]['va_number']
            ?? $data['permata_va_number']
            ?? $data['bill_key']
            ?? null;

        $qrUrl = null;
        foreach (($data['actions'] ?? []) as $act) {
            if (($act['name'] ?? '') === 'generate-qr-code') {
                $qrUrl = $act['url'];
                break;
            }
        }

        $trx->update([
            'order_id' => $orderId,
            'channel' => $channel,
            'bank' => $bank,
            'gross_amount' => $gross,
            'base_amount' => $base,
            'customer_admin_fee' => $customerFee,
            'va_number' => $vaNumber,
            'qris_payload' => $qrUrl,
            'status' => $this->mapStatus($data['transaction_status'] ?? 'pending'),
            'raw_response' => json_encode($data),
        ]);

        return $trx->fresh();
    }

    /**
     * Bagian payload Core API spesifik per metode bayar.
     * Catatan: Mandiri = echannel (bukan VA); Permata = payment_type permata.
     */
    protected function chargeMethodPayload(string $channel, ?string $bank, string $desc): array
    {
        if ($channel === 'qris') {
            return ['payment_type' => 'qris'];
        }

        return match ($bank) {
            'permata' => ['payment_type' => 'permata'],
            'mandiri' => [
                'payment_type' => 'echannel',
                'echannel' => ['bill_info1' => 'Pembayaran', 'bill_info2' => mb_substr($desc, 0, 50)],
            ],
            'bca', 'bni', 'bri', 'cimb' => [
                'payment_type' => 'bank_transfer',
                'bank_transfer' => ['bank' => $bank],
            ],
            default => throw new RuntimeException('Silakan pilih bank untuk VA/Transfer.'),
        };
    }

    /**
     * Buat transaksi Snap untuk tautan pembayaran (/pay) → kembalikan redirect_url ke
     * halaman pembayaran HOSTED milik Midtrans (semua metode aktif tampil, ber-logo
     * Midtrans). Berbeda dari chargeForLink() yang menampilkan instruksi in-page via
     * Core API, Snap tidak butuh aktivasi channel Core API dan berjalan penuh di
     * sandbox — dipakai antara lain untuk tangkapan layar pendaftaran merchant.
     *
     * Metode yang benar-benar dipilih pembeli baru diketahui saat webhook settlement
     * (payment_type) → channel di-update di handleNotification agar jurnal akurat.
     */
    public function createSnapForLink(MidtransTransaction $trx, string $channel, ?int $amount = null): MidtransTransaction
    {
        $isSo = $trx->sales_order_id && !$trx->sales_invoice_id;

        if ($isSo) {
            $so = $trx->salesOrder;
            if (!$so) {
                throw new RuntimeException('Sales Order tidak ditemukan untuk transaksi ini.');
            }
            $base = (int) round($amount ?? 0);
            $itemId = 'SODP-' . $so->id;
            $itemName = 'DP ' . $so->order_number;
        } else {
            $invoice = $trx->invoice;
            if (!$invoice) {
                throw new RuntimeException('Invoice tidak ditemukan untuk transaksi ini.');
            }
            $base = (int) round($invoice->remaining_amount);
            $itemId = 'INV-' . $invoice->id;
            $itemName = 'Invoice ' . $invoice->invoice_number;
        }

        if ($base <= 0) {
            throw new RuntimeException('Nominal pembayaran tidak valid atau sudah lunas.');
        }

        // Biaya admin AKURAT per metode (customer memilih metode di halaman kita → Snap
        // difilter ke metode itu, jadi biaya yang tampil = biaya yang ditagih).
        $customerFee = $this->fees->customerCharge($base, $channel);
        $gross = $base + $customerFee;

        $expiryDays = max(1, (int) ($this->settings()->link_expiry_days ?: 1));
        $orderId = $this->links->makeOrderId($isSo ? 'SODP' : 'LINK');

        $items = [[
            'id' => $itemId,
            'price' => $base,
            'quantity' => 1,
            'name' => mb_substr($itemName, 0, 50),
        ]];
        if ($customerFee > 0) {
            $items[] = ['id' => 'ADMIN-FEE', 'price' => $customerFee, 'quantity' => 1, 'name' => 'Biaya Admin'];
        }

        $payload = [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $gross],
            'item_details' => $items,
            'customer_details' => $this->customerDetails($trx),
            'callbacks' => ['finish' => url('/pay/' . $trx->link_token . '/done')],
            'expiry' => ['unit' => 'day', 'duration' => $expiryDays],
            'enabled_payments' => $this->enabledPaymentsForGroup($channel),
        ];

        $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->authHeader(),
            ])
            ->timeout(20)
            ->post($this->snapBase() . '/snap/v1/transactions', $payload);

        $data = $response->json() ?? [];
        if (!$response->successful() || empty($data['redirect_url'])) {
            Log::error('Midtrans Snap link failed', [
                'order_id' => $orderId,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Midtrans menolak pembuatan halaman pembayaran: ' . ($data['error_messages'][0] ?? $response->body()));
        }

        $trx->update([
            'order_id' => $orderId,
            'channel' => $channel, // group metode (qris/va/ewallet/credit_card/alfamart/paylater)
            'gross_amount' => $gross,
            'base_amount' => $base,
            'customer_admin_fee' => $customerFee,
            'snap_token' => $data['token'] ?? null,
            'snap_redirect_url' => $data['redirect_url'],
            'status' => 'pending',
            'raw_response' => json_encode($data),
        ]);

        return $trx->fresh();
    }

    /** Petakan payment_type webhook → grup channel internal (untuk jurnal). */
    protected function channelFromPaymentType(array $payload): ?string
    {
        return match ($payload['payment_type'] ?? null) {
            'bank_transfer', 'echannel', 'permata' => 'va',
            'qris' => 'qris',
            'gopay', 'shopeepay' => 'ewallet',
            'cstore' => 'alfamart',
            'akulaku', 'kredivo' => 'paylater',
            'credit_card' => 'credit_card',
            default => null,
        };
    }

    /**
     * Kode enabled_payments Snap per grup metode → Snap difilter hanya menampilkan
     * metode yang dipilih pembeli di halaman kita (agar biaya admin = yang ditagih).
     */
    protected function enabledPaymentsForGroup(string $group): array
    {
        return match ($group) {
            'qris' => ['qris', 'other_qris'],
            'va' => ['bca_va', 'bni_va', 'bri_va', 'cimb_va', 'permata_va', 'echannel', 'other_va'],
            'ewallet' => ['gopay', 'shopeepay'],
            'credit_card' => ['credit_card'],
            'alfamart' => ['alfamart'],
            'paylater' => ['akulaku', 'kredivo'],
            default => [],
        };
    }

    /**
     * Buat QRIS in-store via Snap (filter ke QRIS-only) — Snap overlay langsung tampilkan QR
     * karena enabled_payments cuma QRIS. Lebih portable dari Core API /v2/charge yang butuh
     * aktivasi acquirer khusus di Sandbox.
     */
    public function createQrisInstore(SalesInvoice $invoice, ?int $userId = null): MidtransTransaction
    {
        $base = (int) round($invoice->remaining_amount);
        if ($base <= 0) {
            throw new RuntimeException('Invoice ini sudah lunas.');
        }

        // "Tampilkan QRIS yang tadi": pakai ulang QR pending yang masih hidup untuk
        // invoice ini (hindari QR/trx menumpuk saat operator menampilkan ulang).
        $live = MidtransTransaction::where('sales_invoice_id', $invoice->id)
            ->where('channel', 'qris')
            ->where('status', 'pending')
            ->where('expired_at', '>', now())
            ->whereNotNull('snap_token')
            ->latest('id')->first();
        if ($live) {
            return $live;
        }

        $expiryMin = (int) ($this->settings()->qris_expiry_minutes ?: 15);
        $orderId = $this->links->makeOrderId('QRIS');

        $trx = MidtransTransaction::create([
            'order_id' => $orderId,
            'sales_invoice_id' => $invoice->id,
            'sales_order_id' => $invoice->sales_order_id,
            'customer_id' => $invoice->customer_id,
            'source' => 'instore',
            'channel' => 'qris',
            'gross_amount' => $base,
            'base_amount' => $base,
            'customer_admin_fee' => 0,
            'status' => 'pending',
            'expired_at' => now()->addMinutes($expiryMin),
            'created_by' => $userId,
        ]);

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $base,
            ],
            'item_details' => [[
                'id' => 'INV-' . $invoice->id,
                'price' => $base,
                'quantity' => 1,
                'name' => 'Invoice ' . $invoice->invoice_number,
            ]],
            'customer_details' => $this->customerDetails($trx),
            'enabled_payments' => ['qris', 'other_qris'],
            // Kalau Snap menutup/redirect (mis. popup ditutup), kembali ke Kasir —
            // BUKAN ke finish URL default dashboard (example.com). Status tetap dipantau
            // via polling backend + webhook.
            'callbacks' => ['finish' => route('pos.kasir')],
            'expiry' => [
                'unit' => 'minute',
                'duration' => $expiryMin,
            ],
        ];

        $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->authHeader(),
            ])
            ->timeout(20)
            ->post($this->snapBase() . '/snap/v1/transactions', $payload);

        if (!$response->successful()) {
            $trx->update([
                'status' => 'failure',
                'raw_response' => $response->body(),
            ]);
            Log::error('Midtrans Snap QRIS failed', [
                'order_id' => $orderId,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Midtrans menolak request QRIS: ' . ($response->json('error_messages.0') ?? $response->body()));
        }

        $data = $response->json();

        $trx->update([
            'snap_token' => $data['token'] ?? null,
            'snap_redirect_url' => $data['redirect_url'] ?? null,
            'raw_response' => json_encode($data),
        ]);

        return $trx->fresh();
    }

    /**
     * QRIS in-store untuk SALES ORDER (DP/pelunasan di kasir, sebelum invoice ada).
     * Mirror createQrisInstore(): sales_order_id diisi, sales_invoice_id null → settlement
     * webhook menempuh jalur SO-DP yang sudah ada (lihat $isSo di handleNotification).
     */
    public function createQrisInstoreForSO(SalesOrder $so, ?int $userId = null): MidtransTransaction
    {
        $base = (int) round((float) $so->grand_total - (float) $so->paid_amount);
        if ($base <= 0) {
            throw new RuntimeException('Sales Order ini sudah lunas.');
        }

        $expiryMin = (int) ($this->settings()->qris_expiry_minutes ?: 15);
        $orderId = $this->links->makeOrderId('SOQR');

        $trx = MidtransTransaction::create([
            'order_id' => $orderId,
            'sales_invoice_id' => null,
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'source' => 'instore',
            'channel' => 'qris',
            'gross_amount' => $base,
            'base_amount' => $base,
            'customer_admin_fee' => 0,
            'status' => 'pending',
            'expired_at' => now()->addMinutes($expiryMin),
            'created_by' => $userId,
        ]);

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $base,
            ],
            'item_details' => [[
                'id' => 'SO-' . $so->id,
                'price' => $base,
                'quantity' => 1,
                'name' => 'SO ' . $so->order_number,
            ]],
            'customer_details' => $this->customerDetails($trx),
            'enabled_payments' => ['qris', 'other_qris'],
            'expiry' => [
                'unit' => 'minute',
                'duration' => $expiryMin,
            ],
        ];

        $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->authHeader(),
            ])
            ->timeout(20)
            ->post($this->snapBase() . '/snap/v1/transactions', $payload);

        if (!$response->successful()) {
            $trx->update([
                'status' => 'failure',
                'raw_response' => $response->body(),
            ]);
            Log::error('Midtrans Snap QRIS (SO) failed', [
                'order_id' => $orderId,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Midtrans menolak request QRIS: ' . ($response->json('error_messages.0') ?? $response->body()));
        }

        $data = $response->json();

        $trx->update([
            'snap_token' => $data['token'] ?? null,
            'snap_redirect_url' => $data['redirect_url'] ?? null,
            'raw_response' => json_encode($data),
        ]);

        return $trx->fresh();
    }

    /* ===================================================================
       WEBHOOK HANDLER
       =================================================================== */

    /**
     * Idempotent: aman dipanggil berulang kali oleh Midtrans (mereka retry).
     * Skip jika status sudah final & customer_payment_id sudah ada.
     */
    public function handleNotification(array $payload): MidtransTransaction
    {
        $orderId = $payload['order_id'] ?? null;
        if (!$orderId) {
            throw new RuntimeException('Webhook tanpa order_id.');
        }

        return DB::transaction(function () use ($orderId, $payload) {
            $trx = MidtransTransaction::where('order_id', $orderId)->lockForUpdate()->first();
            if (!$trx) {
                throw new RuntimeException("MidtransTransaction tidak ditemukan: {$orderId}");
            }

            $newStatus = $this->mapStatus($payload['transaction_status'] ?? 'pending');
            $fraud = $payload['fraud_status'] ?? null;

            // Idempotency guard: kalau sudah settlement+sudah posting payment, skip.
            if ($trx->status === 'settlement' && $trx->customer_payment_id) {
                $trx->update(['raw_response' => json_encode($payload)]);
                return $trx;
            }

            $update = [
                'status' => $newStatus,
                'fraud_status' => $fraud,
                'transaction_time' => $payload['transaction_time'] ?? null,
                'raw_response' => json_encode($payload),
            ];

            // Capture VA number jika ada (untuk audit trail)
            if (!empty($payload['va_numbers'][0]['va_number'])) {
                $update['va_number'] = $payload['va_numbers'][0]['va_number'];
            }
            if (!empty($payload['permata_va_number'])) {
                $update['va_number'] = $payload['permata_va_number'];
            }

            // Settlement amount real (jika Midtrans kirim)
            if (isset($payload['settlement_amount'])) {
                $update['midtrans_fee'] = max(0, (float) $payload['gross_amount'] - (float) $payload['settlement_amount']);
            }

            if ($newStatus === 'settlement') {
                $update['settled_at'] = now();
            }

            // Snap: metode baru diketahui saat settlement → set channel dari payment_type
            // agar jurnal (mdr/beban gateway) mengikuti metode yang benar-benar dipakai.
            if ($trx->channel === 'snap') {
                $mapped = $this->channelFromPaymentType($payload);
                if ($mapped) {
                    $update['channel'] = $mapped;
                    $update['bank'] = $payload['va_numbers'][0]['bank'] ?? $trx->bank;
                }
            }

            $trx->update($update);

            if ($newStatus === 'settlement' && $fraud !== 'deny' && !$trx->customer_payment_id) {
                // Uang masuk untuk dokumen yang sudah dibatalkan (pembeli membayar VA lama
                // tepat sebelum/sesudah void). Uangnya nyata, tapi tidak boleh dialokasikan
                // ke dokumen mati — catat sebagai peringatan agar admin menanganinya manual
                // (jadikan uang muka pelanggan atau proses refund).
                if ($reason = $trx->fresh()->documentBlockedReason()) {
                    Log::warning('Pembayaran Midtrans masuk untuk dokumen yang sudah dibatalkan — TIDAK diposting otomatis', [
                        'order_id' => $trx->order_id,
                        'amount' => (float) $trx->gross_amount,
                        'alasan' => $reason,
                    ]);
                } else {
                    $this->postCustomerPayment($trx->fresh());
                }
            }

            return $trx->fresh();
        });
    }

    /**
     * Matikan transaksi yang masih pending DI SISI MIDTRANS (/v2/{order_id}/expire).
     *
     * Penting untuk dokumen yang dibatalkan: menonaktifkan tautan di ERP saja tidak cukup
     * kalau nomor VA / QRIS-nya sudah terlanjur terbit — pembeli masih bisa membayarnya
     * dari aplikasi bank tanpa membuka halaman kita lagi.
     *
     * Best-effort: transaksi yang belum pernah di-charge memang belum dikenal Midtrans,
     * dan kegagalan jaringan tidak boleh menggagalkan proses void/hapus dokumen.
     */
    public function expireAtGateway(MidtransTransaction $trx): bool
    {
        // Belum pernah di-charge → tidak ada apa pun di Midtrans untuk di-expire.
        if (! $trx->raw_response && ! $trx->snap_token && ! $trx->qris_payload) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => $this->authHeader(),
                ])
                ->timeout(15)
                ->post($this->apiBase() . '/v2/' . $trx->order_id . '/expire');

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Midtrans expire gagal', ['order_id' => $trx->order_id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Refresh status dari Midtrans (untuk polling QRIS atau manual re-check).
     */
    public function refreshStatus(MidtransTransaction $trx): MidtransTransaction
    {
        $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => $this->authHeader(),
            ])
            ->timeout(15)
            ->get($this->apiBase() . '/v2/' . $trx->order_id . '/status');

        if ($response->successful()) {
            return $this->handleNotification($response->json());
        }

        return $trx;
    }

    /**
     * Tarik status semua transaksi yang masih `pending` langsung dari Midtrans.
     *
     * JARING PENGAMAN, bukan jalur utama. Jalur normalnya webhook — tapi webhook bisa
     * tidak sampai karena hal-hal di luar kode: server sedang restart, deploy berjalan,
     * URL notifikasi salah, atau tunnel mati. Tanpa penarikan seperti ini, notifikasi
     * yang hilang TIDAK PERNAH tersusul: pelanggan sudah membayar, ERP diam saja.
     *
     * Aman dijalankan berulang — handleNotification() punya penjaga idempoten, jadi
     * transaksi yang sudah settlement dan sudah dibuatkan pembayaran tidak diproses lagi.
     *
     * Kegagalan satu transaksi tidak menghentikan sisanya: yang paling mungkin gagal
     * justru transaksi lama yang tidak dikenal Midtrans, dan itu tidak boleh menghalangi
     * transaksi baru yang benar-benar perlu diperbarui.
     *
     * @param  int   $limit    batas transaksi per jalan, menjaga cron tidak berlarut
     * @param  bool  $dryRun   hanya melaporkan, tidak menyentuh data
     * @return array{checked: int, updated: array<int,array{order_id: string, from: string, to: string}>,
     *               unchanged: int, not_found: int, failed: array<int,array{order_id: string, error: string}>}
     */
    public function reconcilePending(int $limit = 200, bool $dryRun = false): array
    {
        $pending = MidtransTransaction::where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $out = ['checked' => 0, 'updated' => [], 'unchanged' => 0, 'not_found' => 0, 'failed' => []];

        foreach ($pending as $trx) {
            $out['checked']++;

            try {
                $response = Http::withHeaders([
                        'Accept'        => 'application/json',
                        'Authorization' => $this->authHeader(),
                    ])
                    ->timeout(15)
                    ->get($this->apiBase() . '/v2/' . $trx->order_id . '/status');

                if ($response->status() === 404) {
                    // Transaksi tidak pernah benar-benar dibuat di Midtrans (mis. Snap
                    // dibuat tapi pelanggan tidak pernah membuka halaman bayarnya).
                    $out['not_found']++;
                    continue;
                }

                if (!$response->successful()) {
                    $out['failed'][] = ['order_id' => $trx->order_id, 'error' => 'HTTP ' . $response->status()];
                    continue;
                }

                $payload   = $response->json();
                $newStatus = $this->mapStatus($payload['transaction_status'] ?? 'pending');

                if ($newStatus === 'pending') {
                    $out['unchanged']++;
                    continue;
                }

                if (!$dryRun) {
                    $this->handleNotification($payload);
                }

                $out['updated'][] = ['order_id' => $trx->order_id, 'from' => 'pending', 'to' => $newStatus];
            } catch (\Throwable $e) {
                Log::warning('Midtrans reconcile gagal', ['order_id' => $trx->order_id, 'error' => $e->getMessage()]);
                $out['failed'][] = ['order_id' => $trx->order_id, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /* ===================================================================
       INTERNAL HELPERS
       =================================================================== */

    /**
     * Buat CustomerPayment + alokasi ke invoice + post journal.
     */
    protected function postCustomerPayment(MidtransTransaction $trx): void
    {
        $isSo    = $trx->sales_order_id && !$trx->sales_invoice_id; // link DP Sales Order
        $invoice = $trx->invoice;
        $so      = $trx->salesOrder;

        if ($isSo && !$so) {
            Log::warning('Midtrans settle SO tanpa sales order', ['trx' => $trx->id]);
            return;
        }
        if (!$isSo && !$invoice) {
            Log::warning('Midtrans settle tanpa invoice', ['trx' => $trx->id]);
            return;
        }

        $base        = (int) round($trx->base_amount);          // porsi tagihan invoice / nominal DP
        $customerFee = (int) round($trx->customer_admin_fee);   // Rp2.000 yg dibayar customer
        $gross       = (int) round($trx->gross_amount);         // base + customerFee
        $mdr         = $this->fees->mdrFee($gross, $trx->channel); // potongan Midtrans (tarif setting)

        // Beban Midtrans bersih = MDR dikurangi kontribusi customer. Hasil:
        //   Dr Saldo Midtrans = amount - netFee = gross - mdr  (uang real masuk)
        //   Dr Beban Gateway  = netFee          = mdr - customerFee
        //   Cr Piutang        = amount          = base
        // Clamp ke base: jaga Dr kas ≥ 0 & lolos validasi admin_fee ≤ amount di
        // CustomerPaymentService. Hanya relevan utk invoice sangat kecil (< tarif MDR).
        $netFee = min(max(0, $mdr - $customerFee), $base);

        $cashAccountId = $this->cashAccountIdFor($trx->channel);
        $feeAccountId  = Account::where('code', '5290')->value('id');

        $payment = $this->payments->create([
            'customer_id' => $trx->customer_id,
            'date' => now()->toDateString(),
            'cash_account_id' => $cashAccountId,
            'amount' => $base,
            'admin_fee' => $netFee,
            'payment_type' => $isSo ? 'advance' : 'invoice',
            'sales_order_id' => $isSo ? $so->id : null,
            'notes' => 'Midtrans ' . $this->channelLabel($trx) . " | order_id={$trx->order_id}",
        ]);

        // fee_account_id tidak diterima CustomerPaymentService::create — set manual.
        if ($netFee > 0) {
            $payment->fee_account_id = $feeAccountId;
            $payment->save();
        }

        // post() meng-handle alokasi + jurnal (lihat allocateToItems).
        // SO → uang muka (Cr 2105) + buat SalesAdvance + naikkan SO.paid_amount.
        if ($isSo) {
            $this->payments->post($payment->id, null, [], [$so->id], false);
        } else {
            $this->payments->post($payment->id, null, [$invoice->id], [], false);
        }

        $trx->update([
            'customer_payment_id' => $payment->id,
            'midtrans_fee' => $mdr,
        ]);
    }

    protected function channelLabel(MidtransTransaction $trx): string
    {
        $bank = $trx->bank ? ' ' . strtoupper($trx->bank) : '';
        return match ($trx->channel) {
            'qris' => 'QRIS',
            'va' => 'VA' . $bank,
            'bank_transfer' => 'Transfer Bank' . $bank,
            default => strtoupper($trx->channel),
        };
    }

    protected function cashAccountIdFor(string $channel): int
    {
        $code = MidtransTransaction::cashAccountCodeFor($channel);
        $id = Account::where('code', $code)->value('id');
        if (!$id) {
            throw new RuntimeException("Akun kas Midtrans (kode {$code}) belum dibuat. Jalankan migration.");
        }
        return $id;
    }

    protected function customerDetails(MidtransTransaction $trx): array
    {
        $customer = $trx->customer;
        $name = $customer?->name ?? 'Customer';
        $parts = preg_split('/\s+/', trim($name), 2);
        return [
            'first_name' => $parts[0] ?? $name,
            'last_name' => $parts[1] ?? '',
            'email' => $customer?->email ?: 'noreply@noud.local',
            'phone' => $customer?->phone ?: '',
        ];
    }

    protected function mapStatus(string $midtransStatus): string
    {
        return match ($midtransStatus) {
            'capture', 'settlement' => 'settlement',
            'pending' => 'pending',
            'deny' => 'deny',
            'cancel' => 'cancel',
            'expire' => 'expire',
            'refund', 'partial_refund' => 'refund',
            'failure' => 'failure',
            default => 'pending',
        };
    }
}
