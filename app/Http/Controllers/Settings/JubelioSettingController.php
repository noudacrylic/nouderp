<?php

namespace App\Http\Controllers\Settings;

use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\Customer;
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
        $channelMaps = JubelioChannelMap::with('customer')->orderBy('store')->get();

        return view('erp.settings.jubelio.edit', compact('setting', 'warehouses', 'marketplaceCustomers', 'channelMaps'));
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

        return back()->with('success', sprintf(
            'Cek & samakan stok selesai — %d dikoreksi, %d sudah sama, %d gagal. Lihat detail di Riwayat Sinkron.',
            $stats['pushed'], $stats['skipped'], $stats['failed']
        ));
    }

    /** Pemetaan nama toko/channel Jubelio → customer marketplace ERP. */
    public function storeChannelMap(Request $request)
    {
        $data = $request->validate([
            'store'       => 'required|string|max:255',
            'customer_id' => 'required|exists:customers,id',
        ]);

        JubelioChannelMap::updateOrCreate(
            ['store' => trim($data['store'])],
            ['customer_id' => $data['customer_id'], 'is_active' => true]
        );

        return back()->with('success', 'Pemetaan toko Jubelio tersimpan.');
    }

    public function destroyChannelMap($id)
    {
        JubelioChannelMap::findOrFail($id)->delete();
        return back()->with('success', 'Pemetaan toko dihapus.');
    }
}
