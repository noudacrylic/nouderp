<?php

namespace App\Http\Controllers\Settings;

use App\Core\Accounting\Account;
use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioStockSyncService;
use Illuminate\Http\Request;

class JubelioSettingController extends Controller
{
    public function edit()
    {
        $setting    = JubelioSetting::singleton();
        $warehouses = Warehouse::orderBy('name')->get();
        // Customer marketplace untuk fallback nama pelanggan.
        $marketplaceCustomers = Customer::where('is_marketplace', true)->orderBy('name')->get();
        // Pemetaan toko + konfigurasi akuntansi marketplace (digabung di satu tempat).
        $channelMaps = JubelioChannelMap::with([
            'customer.marketplace.holdAccount',
            'customer.marketplace.feeAccount',
            'customer.marketplace.walletAccount',
        ])->orderBy('store')->get();
        $accounts = Account::orderBy('code')->get();

        return view('erp.settings.jubelio.edit', compact('setting', 'warehouses', 'marketplaceCustomers', 'channelMaps', 'accounts'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'username'             => 'nullable|string|max:255',
            'password'             => 'nullable|string|max:255',
            'base_url'             => 'nullable|url|max:255',
            'is_production'        => 'nullable|boolean',
            'is_active'            => 'nullable|boolean',
            'webhook_secret'       => 'nullable|string|max:255',
            'default_warehouse_id' => 'nullable|exists:warehouses,id',
            'default_location_id'  => 'nullable|integer',
            'default_customer_id'  => 'nullable|exists:customers,id',
        ]);

        $setting = JubelioSetting::singleton();

        // Jangan timpa password dengan kosong (form tidak menampilkan password tersimpan).
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $data['is_production'] = $request->boolean('is_production');
        $data['is_active']     = $request->boolean('is_active');
        $data['base_url']      = $data['base_url'] ?: JubelioSetting::DEFAULT_BASE_URL;

        // Ganti kredensial → buang token cache agar login ulang.
        if (($data['username'] ?? null) !== $setting->username || isset($data['password'])) {
            $data['access_token']     = null;
            $data['token_expires_at'] = null;
        }

        $setting->update($data);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Pengaturan Jubelio tersimpan.');
    }

    /** Uji kredensial: coba login ke Jubelio. */
    public function testConnection()
    {
        $result = app(JubelioClient::class)->login();

        if ($result['success']) {
            return back()->with('success', 'Koneksi Jubelio berhasil — token diperbarui.');
        }
        return back()->withErrors(['jubelio' => 'Koneksi gagal: ' . $result['error']]);
    }

    /**
     * Cek & samakan stok sekarang (reconcile manual). Memanggil service yang sama
     * dengan cron jubelio:reconcile-stock — membaca stok aktual Jubelio (GET) lalu
     * mengoreksi selisihnya. Hasil per-produk tercatat di Riwayat Sinkron.
     */
    public function reconcileStock(JubelioStockSyncService $stock)
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            return back()->withErrors(['jubelio' => 'Integrasi Jubelio belum dikonfigurasi (isi kredensial & uji koneksi dulu).']);
        }

        @set_time_limit(300); // reconcile membaca stok per-produk dari Jubelio; beri waktu cukup.
        $stats = $stock->reconcileAll();

        $unmatched = $stats['skipped_unmatched'] ?? 0;
        $msg = sprintf(
            'Cek & samakan stok selesai — %d dikoreksi, %d sudah sama, %d gagal.',
            $stats['pushed'], $stats['skipped'], $stats['failed']
        );
        if ($unmatched > 0) {
            // Jangan campur "belum ter-match / location belum diatur" ke dalam "sudah sama"
            // (menyesatkan). Arahkan user melihat Riwayat Sinkron untuk produk tersebut.
            $msg .= sprintf(' %d produk dilewati (belum ter-match / location belum diatur) — cek Riwayat Sinkron.', $unmatched);
            return back()->with('warning', $msg);
        }

        return back()->with('success', $msg . ' Lihat detail di Riwayat Sinkron.');
    }

    /** Ambil daftar lokasi Jubelio untuk dropdown Location ID (dipanggil via fetch dari form). */
    public function fetchLocations(JubelioClient $client)
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Integrasi Jubelio belum dikonfigurasi (isi kredensial & uji koneksi dulu).'], 422);
        }

        $resp = $client->getLocations();
        if (!$resp['success']) {
            return response()->json(['success' => false, 'message' => 'Gagal mengambil lokasi dari Jubelio: ' . ($resp['error'] ?? 'tidak diketahui')], 502);
        }

        // getLocations() mengembalikan respons mentah Jubelio { data: [...] } di key 'data'.
        $rows = $resp['data']['data'] ?? (is_array($resp['data']) ? $resp['data'] : []);
        $locations = collect($rows)->map(fn ($r) => [
            'id'   => (int) ($r['location_id'] ?? 0),
            'name' => $r['location_name'] ?? $r['name'] ?? ('Lokasi ' . ($r['location_id'] ?? '?')),
        ])->values();

        return response()->json(['success' => true, 'locations' => $locations]);
    }

    /**
     * Langkah 1 — Pemetaan toko Jubelio → customer marketplace (tanpa akuntansi).
     * customer_id boleh berupa id existing ATAU "new:<nama>" (auto-buat customer marketplace).
     * Potongan & akun diisi terpisah per-baris (lihat saveMarketplaceConfig).
     */
    public function storeChannelMap(Request $request)
    {
        $data = $request->validate([
            'store'       => 'required|string|max:255',
            'customer_id' => 'required|string',
        ]);

        // Resolusi customer: id existing atau "new:<nama>" → buat baru bertipe marketplace.
        $raw = trim($data['customer_id']);
        if (str_starts_with($raw, 'new:')) {
            $name = trim(substr($raw, 4));
            if ($name === '') {
                return back()->withErrors(['customer_id' => 'Nama customer kosong.'])->withInput();
            }
            $customer = Customer::firstOrCreate(
                ['name' => $name],
                ['code' => 'CUST-' . time(), 'customer_type' => 'marketplace', 'is_marketplace' => true, 'is_active' => true]
            );
            $customer->forceFill(['customer_type' => 'marketplace', 'is_marketplace' => true])->save();
        } else {
            $customer = ctype_digit($raw) ? Customer::find((int) $raw) : null;
            if (!$customer) {
                return back()->withErrors(['customer_id' => 'Customer tidak valid.'])->withInput();
            }
            if (!$customer->is_marketplace) {
                $customer->forceFill(['is_marketplace' => true])->save();
            }
        }

        JubelioChannelMap::updateOrCreate(
            ['store' => trim($data['store'])],
            ['customer_id' => $customer->id, 'is_active' => true]
        );

        return back()->with('success', 'Pemetaan toko tersimpan. Lengkapi potongan & akun di baris pemetaan.');
    }

    /**
     * Langkah 2 — Konfigurasi akuntansi marketplace untuk satu customer (potongan + akun).
     * Diisi per-baris setelah pemetaan toko ada. Ketiga akun wajib (alur DP/saldo ditahan).
     */
    public function saveMarketplaceConfig(Request $request)
    {
        $data = $request->validate([
            'customer_id'                => 'required|exists:customers,id',
            'admin_fee_percent'          => 'nullable|numeric|min:0',
            'admin_fee_fixed'            => 'nullable|numeric|min:0',
            'account_receivable_hold_id' => 'required|exists:accounts,id',
            'account_fee_id'             => 'required|exists:accounts,id',
            'account_wallet_id'          => 'required|exists:accounts,id',
        ]);

        MarketplaceConfig::updateOrCreate(
            ['customer_id' => $data['customer_id']],
            [
                'admin_fee_percent'          => $data['admin_fee_percent'] ?? 0,
                'admin_fee_fixed'            => $data['admin_fee_fixed'] ?? 0,
                'account_receivable_hold_id' => $data['account_receivable_hold_id'],
                'account_fee_id'             => $data['account_fee_id'],
                'account_wallet_id'          => $data['account_wallet_id'],
                'is_active'                  => true,
            ]
        );

        return back()->with('success', 'Potongan & akun marketplace tersimpan.');
    }

    public function destroyChannelMap($id)
    {
        JubelioChannelMap::findOrFail($id)->delete();
        return back()->with('success', 'Pemetaan toko dihapus.');
    }
}
