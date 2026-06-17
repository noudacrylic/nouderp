<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceConfig;
use App\Models\Customer;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use Illuminate\Http\Request;

class MarketplaceConfigController extends Controller
{
    /** Ambil daftar toko Jubelio untuk pilihan platform (dipanggil via fetch dari modal). */
    public function fetchStores(JubelioClient $client)
    {
        if (!JubelioSetting::singleton()->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Integrasi Jubelio belum dikonfigurasi (Settings → Jubelio).'], 422);
        }

        $resp = $client->getStores();
        if (!$resp['success']) {
            return response()->json(['success' => false, 'message' => 'Gagal mengambil toko dari Jubelio: ' . ($resp['error'] ?? 'tidak diketahui')], 502);
        }

        // channel_id → nama marketplace (lihat tabel "Channels" di spec Jubelio).
        $channels = [
            1 => 'Internal', 2 => 'Bukalapak', 4 => 'Lazada', 8 => 'Zalora', 16 => 'Elevenia',
            // TikTok & Tokopedia kini satu sumber (merger) → label digabung jadi "TikTok Tokopedia".
            32 => 'Blibli', 64 => 'Shopee', 128 => 'TikTok Tokopedia', 512 => 'Blanja', 2048 => 'Zilingo',
            4096 => 'JD', 65536 => 'Akulaku', 131072 => 'Webstore', 262144 => 'Dealpos',
            524288 => 'Jubelio POS', 1048576 => 'Shopify', 131076 => 'TikTok Tokopedia',
        ];

        $rows = $resp['data']['data'] ?? (is_array($resp['data']) ? $resp['data'] : []);
        $stores = collect($rows)
            ->map(fn ($r) => [
                // value yang disimpan = store_id (kunci routing pesanan yg UNIK & stabil;
                // store_name bisa bentrok antar channel, mis. Shopee & Tokopedia sama persis).
                'id'      => (int) ($r['store_id'] ?? 0),
                'name'    => trim((string) ($r['store_name'] ?? '')),
                // label marketplace hanya untuk tampilan, bantu bedakan Shopee/Tokopedia/TikTok.
                'channel' => $channels[(int) ($r['channel_id'] ?? 0)] ?? null,
            ])
            ->filter(fn ($s) => $s['id'] > 0)
            ->unique('id')
            ->values();

        return response()->json(['success' => true, 'stores' => $stores]);
    }

    public function index()
    {
        // Konfigurasi marketplace kini digabung ke Settings → Jubelio (Pemetaan Toko + Akun).
        return redirect()->route('settings.jubelio.edit');
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|string',
            'admin_fee_percent' => 'nullable|numeric|min:0',
            'admin_fee_fixed' => 'nullable|numeric|min:0',
            'account_receivable_hold_id' => 'required|exists:accounts,id',
            'account_fee_id' => 'required|exists:accounts,id',
            'account_wallet_id' => 'required|exists:accounts,id',
        ]);

        $raw = trim((string) $request->customer_id);

        // Nilai "new:<store_name>" → buat customer marketplace baru + petakan channel
        // (store_name HARUS persis nama toko Jubelio agar pesanan masuk ter-route ke customer ini).
        if (str_starts_with($raw, 'new:')) {
            $name = trim(substr($raw, 4));
            if ($name === '') {
                return back()->withErrors(['customer_id' => 'Nama toko/pelanggan kosong.'])->withInput();
            }

            $customer = Customer::firstOrCreate(
                ['name' => $name],
                ['code' => 'CUST-' . time(), 'customer_type' => 'marketplace', 'is_marketplace' => true, 'is_active' => true]
            );
            // Pastikan flag marketplace walau customer sudah ada sebelumnya dengan tipe lain.
            $customer->forceFill(['customer_type' => 'marketplace', 'is_marketplace' => true])->save();

            // Pemetaan toko Jubelio → customer (kunci routing pesanan). Pakai nama sebagai store.
            JubelioChannelMap::updateOrCreate(
                ['store' => $name],
                ['customer_id' => $customer->id, 'is_active' => true]
            );

            $customerId = $customer->id;
        } else {
            if (!ctype_digit($raw) || !Customer::whereKey($raw)->exists()) {
                return back()->withErrors(['customer_id' => 'Pelanggan tidak valid.'])->withInput();
            }
            $customerId = (int) $raw;
        }

        if (MarketplaceConfig::where('customer_id', $customerId)->exists()) {
            return back()->withErrors(['customer_id' => 'Pelanggan ini sudah punya konfigurasi marketplace.'])->withInput();
        }

        MarketplaceConfig::create([
            'customer_id' => $customerId,
            'admin_fee_percent' => $request->admin_fee_percent ?? 0,
            'admin_fee_fixed' => $request->admin_fee_fixed ?? 0,
            'account_receivable_hold_id' => $request->account_receivable_hold_id,
            'account_fee_id' => $request->account_fee_id,
            'account_wallet_id' => $request->account_wallet_id,
            'is_active' => true,
        ]);

        Customer::where('id', $customerId)->update(['is_marketplace' => true]);

        return back()->with('success', 'Konfigurasi Marketplace berhasil dibuat.');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'admin_fee_percent' => 'nullable|numeric|min:0',
            'admin_fee_fixed' => 'nullable|numeric|min:0',
            'account_receivable_hold_id' => 'required|exists:accounts,id',
            'account_fee_id' => 'required|exists:accounts,id',
            'account_wallet_id' => 'required|exists:accounts,id',
        ]);

        $config = MarketplaceConfig::findOrFail($id);

        $config->update([
            'admin_fee_percent' => $request->admin_fee_percent ?? 0,
            'admin_fee_fixed' => $request->admin_fee_fixed ?? 0,
            'account_receivable_hold_id' => $request->account_receivable_hold_id,
            'account_fee_id' => $request->account_fee_id,
            'account_wallet_id' => $request->account_wallet_id,
            'is_active' => $request->has('is_active') ? $request->is_active : $config->is_active,
        ]);

        return back()->with('success', 'Konfigurasi Marketplace berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $config = MarketplaceConfig::findOrFail($id);
        $customerId = $config->customer_id;
        $config->delete();

        Customer::where('id', $customerId)->update(['is_marketplace' => false]);

        return back()->with('success', 'Konfigurasi Marketplace berhasil dihapus.');
    }
}
