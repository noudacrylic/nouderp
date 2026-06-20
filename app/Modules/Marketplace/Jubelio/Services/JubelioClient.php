<?php

namespace App\Modules\Marketplace\Jubelio\Services;

use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien API Jubelio (https://api2.jubelio.com). Pola adapter seperti BiteshipProvider.
 *
 * Auth: POST /login {email,password} -> token (berlaku 12 jam). Token dikirim di
 * header "authorization" TANPA prefix "Bearer". Token di-cache di jubelio_settings;
 * di-refresh otomatis saat kedaluwarsa atau saat respons 401.
 *
 * Semua method defensif: kembalikan array {success, data|..., error} dan log warning,
 * tidak melempar exception agar cron/webhook tetap jalan.
 */
class JubelioClient
{
    /** Token Jubelio berlaku 12 jam; simpan expiry konservatif 11 jam. */
    private const TOKEN_TTL_HOURS = 11;

    private JubelioSetting $setting;

    public function __construct(?JubelioSetting $setting = null)
    {
        $this->setting = $setting ?: JubelioSetting::singleton();
    }

    public function isReady(): bool
    {
        return $this->setting->isConfigured();
    }

    // ───────────────────────────── Auth ─────────────────────────────

    /**
     * Login & simpan token. Dipakai juga oleh tombol "Test Koneksi" di Settings.
     * @return array{success:bool, error:?string}
     */
    public function login(): array
    {
        if (empty($this->setting->username) || empty($this->setting->password)) {
            return ['success' => false, 'error' => 'Email/password Jubelio belum diisi.'];
        }

        try {
            $res = Http::acceptJson()->timeout(25)
                ->baseUrl($this->setting->effectiveBaseUrl())
                ->post('/login', [
                    'email'    => $this->setting->username,
                    'password' => $this->setting->password,
                ]);
            $data = $res->json() ?? [];

            $token = $data['token'] ?? null;
            if (!$res->successful() || !$token) {
                $msg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $res->status());
                Log::warning('Jubelio login gagal', ['error' => $msg]);
                return ['success' => false, 'error' => $msg];
            }

            $this->setting->forceFill([
                'access_token'     => $token,
                'token_expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
            ])->save();

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Jubelio login exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Token valid (login bila perlu). Null bila gagal. */
    private function token(): ?string
    {
        if (!$this->setting->hasValidToken()) {
            $login = $this->login();
            if (!$login['success']) {
                return null;
            }
            $this->setting->refresh();
        }
        return $this->setting->access_token;
    }

    private function http(): ?PendingRequest
    {
        $token = $this->token();
        if (!$token) {
            return null;
        }
        // Jubelio: token mentah di header "authorization" (tanpa "Bearer").
        return Http::withHeaders(['authorization' => $token])
            ->acceptJson()
            ->timeout(30)
            ->baseUrl($this->setting->effectiveBaseUrl());
    }

    /**
     * Wrapper request dengan retry sekali bila 401 (token kedaluwarsa di sisi server).
     * @return array{success:bool, status:int, data:mixed, error:?string}
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        $http = $this->http();
        if (!$http) {
            return ['success' => false, 'status' => 0, 'data' => null, 'error' => 'Jubelio belum dikonfigurasi / login gagal.'];
        }

        try {
            $res = $http->send($method, $uri, $options);

            // 401 → paksa login ulang sekali.
            if ($res->status() === 401) {
                $this->setting->forceFill(['access_token' => null, 'token_expires_at' => null])->save();
                $http = $this->http();
                if (!$http) {
                    return ['success' => false, 'status' => 401, 'data' => null, 'error' => 'Re-login Jubelio gagal.'];
                }
                $res = $http->send($method, $uri, $options);
            }

            $data = $res->json() ?? [];
            if (!$res->successful()) {
                $msg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $res->status());
                Log::warning("Jubelio {$method} {$uri} gagal", ['status' => $res->status(), 'error' => $msg]);
                return ['success' => false, 'status' => $res->status(), 'data' => $data, 'error' => $msg];
            }

            return ['success' => true, 'status' => $res->status(), 'data' => $data, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning("Jubelio {$method} {$uri} exception", ['error' => $e->getMessage()]);
            return ['success' => false, 'status' => 0, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    private function get(string $uri, array $query = []): array
    {
        return $this->request('GET', $uri, ['query' => $query]);
    }

    private function post(string $uri, array $body = []): array
    {
        return $this->request('POST', $uri, ['json' => $body]);
    }

    // ───────────────────────────── Pesanan ─────────────────────────────

    /** Detail 1 sales order (lengkap dengan item). Webhook hanya kirim notifikasi. */
    public function getOrder(int $salesOrderId): array
    {
        return $this->get('/sales/orders/' . $salesOrderId);
    }

    /** Pesanan siap diproses gudang (sudah dibayar). */
    public function listReadyToProcess(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/wms/sales/orders/ready-to-process/', ['page' => $page, 'pageSize' => $pageSize]);
    }

    /**
     * SEMUA pesanan (paid + belum bayar + dll). Dipakai untuk menarik pesanan yang BELUM
     * DIBAYAR (channel_status "Unpaid"/"Pending") agar tampil di "Belum Siap" — endpoint
     * ready-to-process hanya memuat yang sudah dibayar. Filter is_paid TIDAK didukung server
     * (ditolak 400), jadi penyaringan dilakukan di sisi pemanggil.
     */
    public function listAllOrders(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/sales/orders/', ['page' => $page, 'pageSize' => $pageSize]);
    }

    /** Pesanan selesai/diterima customer. */
    public function listCompleted(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/sales/orders/completed/', ['page' => $page, 'pageSize' => $pageSize]);
    }

    /** Pesanan yang PEMBELI minta batalkan (request cancel dari marketplace). */
    public function listRequestCancel(int $page = 1, int $pageSize = 100): array
    {
        return $this->get('/wms/sales/orders/request-cancel/', ['page' => $page, 'pageSize' => $pageSize]);
    }

    // ─────────────────── Fulfillment WMS (pick → pack → faktur → resi) ───────────────────
    // Rantai untuk memproses pesanan marketplace dari ERP, semuanya keyed salesorder_id.
    // Lihat spec "Fulfill Orders (Pick,Pack,Ship)" di dokumentasi Jubelio.

    /** Pindahkan order 'ready to process' → 'ready to pick'. Body: {salesorder_ids:[id]}. */
    public function postReadyToPick(array $salesOrderIds): array
    {
        return $this->post('/wms/sales/ready-to-pick', ['salesorder_ids' => array_values($salesOrderIds)]);
    }

    /** Info staff WMS by email/NIK → {employee_id (email), name, nik}. Untuk validasi picker. */
    public function getEmployee(string $nikOrEmail): array
    {
        return $this->get('/wms/employee/' . rawurlencode($nikOrEmail));
    }

    /**
     * Daftar item yang harus dipick untuk SO tertentu. Body key = "ids" (bukan salesorder_ids).
     * Respons: ARRAY baris {salesorder_id, salesorder_detail_id, item_id, location_id (-1=pusat),
     * qty_ordered (string), bundle_item_id, end_qty, ...}.
     */
    public function postItemsToPick(array $salesOrderIds): array
    {
        return $this->post('/sales/picklists/items-to-pick/', ['ids' => array_values($salesOrderIds)]);
    }

    /**
     * Buat picklist sekaligus tandai selesai (varian auto-complete). Kirim picklist_id:0,
     * is_completed:true, qty_picked=qty_ordered. Respons: {status, data:{picks:[...], invalidSO:[]}}.
     */
    public function postPicklistAutoComplete(array $payload): array
    {
        return $this->post('/wms/sales/picklists/', $payload);
    }

    /** Tandai order selesai packing / ready to ship. Body: {ids:[salesorder_id]}. */
    public function postPacklistMarkComplete(array $salesOrderIds): array
    {
        return $this->post('/wms/sales/packlist/mark-as-complete/', ['ids' => array_values($salesOrderIds)]);
    }

    /** Buat faktur Jubelio dari SO. Body: {salesorder_id}. Respons: {status, id}. */
    public function postCreateInvoice(int $salesOrderId): array
    {
        return $this->post('/sales/packlists/create-invoice', ['salesorder_id' => $salesOrderId]);
    }

    /**
     * Minta resi/AWB ke kurir marketplace. Body: {salesorder_id}.
     * Respons sukses: {salesorder_id, shipper, tracking_no, ...}. Gagal 400: {message}.
     */
    public function requestAwb(int $salesOrderId): array
    {
        return $this->post('/sales/request-awb-order/', ['salesorder_id' => $salesOrderId]);
    }

    /**
     * URL label resi (report Jubelio) untuk dicetak. Respons: {status, url, title}.
     * Terima 1 id ATAU banyak id (cetak resi gabungan) — endpoint menerima ids[] jamak.
     * Catatan: serialisasi query `ids[]=` — Jubelio mengharapkan key array tanpa indeks
     * (ids[]=1&ids[]=2), bukan berindeks (ids[0]=), sehingga query dibangun manual.
     */
    public function getShippingLabelUrl(int|array $salesOrderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $salesOrderIds)));
        $qs  = implode('&', array_map(fn ($id) => 'ids[]=' . $id, $ids));

        return $this->request('GET', '/reports/shipping-label/', ['query' => $qs]);
    }

    /** URL faktur (report Jubelio) untuk dicetak, pakai invoice id dari create-invoice. */
    public function getInvoiceReportUrl(int $invoiceId): array
    {
        return $this->get('/reports/invoice', ['ids[]' => $invoiceId]);
    }

    // ───────────────────────────── Retur ─────────────────────────────

    /** Retur belum diproses (untuk dibuat draft di ERP). */
    public function listUnprocessedReturns(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/sales/returns/items/unprocessed/wms', ['page' => $page, 'pageSize' => $pageSize]);
    }

    // ───────────────────────────── Produk / Stok ─────────────────────────────

    /** Cari item Jubelio berdasarkan SKU (untuk match ke Product ERP). */
    public function getItemBySku(string $sku): array
    {
        return $this->get('/inventory/items/by-sku/' . rawurlencode($sku));
    }

    /** Detail item Jubelio by ID (mengandung item_code/SKU untuk match ke Product ERP). */
    public function getItem(int $itemId): array
    {
        return $this->get('/inventory/items/' . $itemId);
    }

    /** Daftar lokasi/gudang Jubelio. Respons: { data: [ {location_id, location_name, ...} ] }. */
    public function getLocations(int $page = 1, int $pageSize = 100): array
    {
        return $this->get('/locations/', ['page' => $page, 'pageSize' => $pageSize]);
    }

    /**
     * Daftar toko/channel Jubelio (untuk pemetaan store → customer marketplace).
     * Respons: { data: [ {channel_id, store_id, store_name, ...} ] }.
     * store_name di sini = field 'store_name' yang muncul di detail order → kunci routing.
     */
    public function getStores(int $page = 1, int $pageSize = 100): array
    {
        return $this->get('/marketplace/store/', ['page' => $page, 'pageSize' => $pageSize]);
    }

    /**
     * Stok available item di sebuah lokasi (untuk rekonsiliasi absolut).
     * Mengembalikan float|null (null bila tak dapat ditentukan dari respons).
     */
    public function getItemAvailable(int $itemId, ?int $locationId = null): ?float
    {
        $resp = $this->getItem($itemId);
        if (!$resp['success'] || !is_array($resp['data'])) {
            return null;
        }

        // Respons by-id adalah objek ITEM-GROUP; stok per-variasi ada di product_skus[].
        // Setup single-location: end_qty = stok di lokasi satu-satunya. Cocokkan baris
        // yang item_id-nya == $itemId; return null bila tak ketemu (jangan menebak).
        $skus = $resp['data']['product_skus'] ?? null;
        if (is_array($skus)) {
            foreach ($skus as $row) {
                if ((int) ($row['item_id'] ?? 0) !== $itemId) {
                    continue;
                }
                if (isset($row['end_qty']) && is_numeric($row['end_qty'])) {
                    return (float) $row['end_qty'];
                }
                return null;
            }
        }

        return null;
    }

    /** Default bin untuk sebuah lokasi (dibutuhkan saat adjustment). */
    public function getDefaultBin(int $locationId): array
    {
        return $this->get('/wms/default-bin/' . $locationId);
    }

    /**
     * Penyesuaian stok (delta). qty_in_base positif menambah, negatif mengurangi.
     * Dipakai Fase 2 untuk push stok ERP → Jubelio.
     *
     * @param array<int,array{item_id:int,qty_in_base:float,cost?:float,bin_id?:int,unit?:string}> $items
     */
    public function postAdjustment(int $locationId, array $items, ?string $note = null): array
    {
        $payload = [
            'item_adj_id'        => 0,
            'item_adj_no'        => '[auto]',
            'transaction_date'   => now()->toIso8601String(),
            'note'               => $note ?? 'Sinkron stok dari Noud ERP',
            'location_id'        => $locationId,
            'is_opening_balance' => false,
            'items'              => array_map(function ($it) use ($locationId) {
                $qty  = (float) ($it['qty_in_base'] ?? 0);
                $cost = (float) ($it['cost'] ?? 0);
                return [
                    'item_adj_detail_id' => 0,
                    'item_id'            => (int) $it['item_id'],
                    'qty_in_base'        => $qty,
                    'unit'               => $it['unit'] ?? 'Pcs',
                    'amount'             => $qty * $cost,
                    'location_id'        => $locationId,
                    'account_id'         => (int) ($it['account_id'] ?? 75), // default Jubelio
                    'bin_id'             => (int) ($it['bin_id'] ?? 0),
                    'cost'               => $cost,
                ];
            }, array_values($items)),
        ];

        return $this->post('/inventory/adjustments/', $payload);
    }

    // ───────────────────────────── Harga (Fase 3) ─────────────────────────────

    /**
     * Edit harga produk (POST /inventory/price-list/). store_id '-1' = harga dasar semua toko.
     * @param array<int,array{item_group_id:int,item_id:int,price:float,store_id?:string}> $items
     */
    public function updatePrices(array $items): array
    {
        $payload = array_map(fn ($it) => [
            'item_group_id' => (int) $it['item_group_id'],
            'item_id'       => (int) $it['item_id'],
            'prices'        => [[
                'store_id' => (string) ($it['store_id'] ?? '-1'),
                'price'    => (float) $it['price'],
            ]],
        ], array_values($items));

        return $this->post('/inventory/price-list/', $payload);
    }
}
