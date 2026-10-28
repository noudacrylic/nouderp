<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeApiLog;
use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use Illuminate\Http\Request;

/**
 * Pengaturan › Integrasi › Shopee Open Platform: kunci app Test/Live, hubungkan toko
 * (OAuth), uji koneksi, dan log panggilan API. Hanya super admin — otorisasi toko memberi
 * akses penuh ke pesanan & saldo.
 */
class ShopeeSettingController extends Controller
{
    public function __construct(private ShopeeClient $client) {}

    public function index(Request $request)
    {
        $this->superAdmin($request);

        return view('erp.settings.shopee.index', [
            'setting' => $this->client->setting(),
            'siap'  => $this->client->siap(),
            'env'   => $this->client->env(),
            'host'  => $this->client->host(),
            'shops' => ShopeeShop::with('penghubung:id,name')->orderByDesc('terhubung_at')->get(),
            'logs'  => ShopeeApiLog::latest('id')->limit(30)->get(),
            'callback' => route('settings.integrations.shopee.callback'),
        ]);
    }

    /**
     * Simpan kunci app. Partner Key dikosongkan = kunci tersimpan tidak diubah (seperti
     * password Jubelio); centang "hapus" untuk mengosongkannya.
     */
    public function simpan(Request $request)
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'env'              => 'required|in:' . implode(',', ShopeeSetting::ENVS),
            'test_partner_id'  => 'nullable|integer|min:1',
            'test_partner_key' => 'nullable|string|max:255',
            'live_partner_id'  => 'nullable|integer|min:1',
            'live_partner_key' => 'nullable|string|max:255',
            'hapus_test_key'   => 'nullable|boolean',
            'hapus_live_key'   => 'nullable|boolean',
            'live_key_expires_at' => 'nullable|date',
        ]);

        $setting = ShopeeSetting::singleton();
        $setting->env = $data['env'];
        foreach (ShopeeSetting::ENVS as $env) {
            $setting->{$env . '_partner_id'} = $data[$env . '_partner_id'] ?? null;
            $kunci = trim((string) ($data[$env . '_partner_key'] ?? ''));
            if ($kunci !== '') {
                $setting->{$env . '_partner_key'} = $kunci;
            } elseif (!empty($data['hapus_' . $env . '_key'])) {
                $setting->{$env . '_partner_key'} = null;
            }
        }
        $setting->live_key_expires_at = $data['live_key_expires_at'] ?? null;
        $setting->save();

        return redirect()->route('settings.integrations.shopee.index')
            ->with('success', 'Pengaturan Shopee disimpan — lingkungan aktif ' . strtoupper($setting->envAktif()) . '.');
    }

    /** Uji pasangan Partner ID + Key lingkungan aktif sebelum menghubungkan toko. */
    public function cekKunci(Request $request)
    {
        $this->superAdmin($request);
        $key   = $this->client->setting()->partnerKey();
        $ciri  = 'Partner ID ' . $this->client->setting()->partnerId() . ', key ' . strlen($key) . ' karakter'
            . (str_starts_with($key, 'shpk') ? ' berawalan "shpk"' : ' TIDAK berawalan "shpk"');

        try {
            $data = $this->client->cekKunci();
        } catch (ShopeeException $e) {
            $saran = $e->kode === 'error_sign'
                ? ' — Partner Key tidak cocok dengan Partner ID ini. Salin ulang Partner Key ' . strtoupper($this->client->env()) . ' dari Console (bukan Live/Test yang lain).'
                : '';

            return back()->with('error', 'Kunci ' . strtoupper($this->client->env()) . ' ditolak Shopee: ' . $e->getMessage() . $saran . ' (' . $ciri . ')');
        }

        $toko = collect($data['authed_shop_list'] ?? [])->pluck('shop_id')->implode(', ');

        return back()->with('success', 'Kunci ' . strtoupper($this->client->env()) . ' diterima Shopee (' . $ciri . ').'
            . ($toko !== '' ? ' Toko yang sudah memberi izin: ' . $toko . '.' : ' Belum ada toko yang memberi izin.'));
    }

    /** Arahkan ke halaman izin Shopee; Shopee kembali ke callback dengan code & shop_id. */
    public function hubungkan(Request $request)
    {
        $this->superAdmin($request);
        if (!$this->client->siap()) {
            return back()->with('error', 'Isi Partner ID & Partner Key ' . strtoupper($this->client->env()) . ' di halaman ini dulu.');
        }

        return redirect()->away($this->client->urlOtorisasi(route('settings.integrations.shopee.callback')));
    }

    public function callback(Request $request)
    {
        $this->superAdmin($request);
        $data = $request->validate(['code' => 'required|string|max:200', 'shop_id' => 'required|integer']);

        try {
            $shop = $this->client->tukarKode($data['code'], (int) $data['shop_id'], $request->user()->id);
        } catch (ShopeeException $e) {
            return redirect()->route('settings.integrations.shopee.index')->with('error', 'Gagal menghubungkan toko: ' . $e->getMessage());
        }

        return redirect()->route('settings.integrations.shopee.index')
            ->with('success', 'Toko ' . ($shop->nama ?: $shop->shop_id) . ' terhubung (' . strtoupper($shop->env) . ').');
    }

    public function uji(Request $request, int $id)
    {
        $this->superAdmin($request);
        $shop = ShopeeShop::findOrFail($id);

        try {
            $info = $this->client->panggil($shop, 'GET', '/api/v2/shop/get_shop_info');
            $shop->update(['nama' => $info['shop_name'] ?? $shop->nama]);
        } catch (ShopeeException $e) {
            return back()->with('error', 'Uji koneksi gagal: ' . $e->getMessage());
        }

        return back()->with('success', 'Koneksi baik — toko "' . ($info['shop_name'] ?? $shop->shop_id) . '"'
            . (isset($info['status']) ? ', status ' . $info['status'] : '') . '.');
    }

    /** Lepas toko dari ERP (token dihapus). Izin di sisi Shopee dicabut dari Seller Centre. */
    public function putuskan(Request $request, int $id)
    {
        $this->superAdmin($request);
        ShopeeShop::findOrFail($id)->delete();

        return back()->with('success', 'Toko dilepas dari ERP. Untuk mencabut izin di sisi Shopee, buka Seller Centre › Pengaturan › Aplikasi.');
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403, 'Hanya super admin yang boleh mengatur integrasi Shopee.');
    }
}
