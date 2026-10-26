<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeApiLog;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use Illuminate\Http\Request;

/**
 * Pengaturan › Integrasi › Shopee Open Platform: status kunci app (.env), hubungkan toko
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
            'siap'  => $this->client->siap(),
            'env'   => $this->client->env(),
            'host'  => $this->client->host(),
            'shops' => ShopeeShop::with('penghubung:id,name')->orderByDesc('terhubung_at')->get(),
            'logs'  => ShopeeApiLog::latest('id')->limit(30)->get(),
            'callback' => route('settings.integrations.shopee.callback'),
        ]);
    }

    /** Arahkan ke halaman izin Shopee; Shopee kembali ke callback dengan code & shop_id. */
    public function hubungkan(Request $request)
    {
        $this->superAdmin($request);
        if (!$this->client->siap()) {
            return back()->with('error', 'Isi SHOPEE_PARTNER_ID dan SHOPEE_PARTNER_KEY di .env server dulu.');
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
