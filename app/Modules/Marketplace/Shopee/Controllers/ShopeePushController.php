<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeApiLog;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use Illuminate\Http\Request;

/**
 * Live Push Shopee (Tahap C chat). URL-nya didaftarkan di Console › App › Push Mechanism.
 *
 * Isi push TIDAK dipercaya sebagai data: ia cuma bel pintu. Untuk push chat, yang dilakukan
 * adalah menarik chat toko itu lewat API seperti cron tiap menit — hanya lebih cepat. Karena
 * itu push yang tanda tangannya salah tetap dijawab 200 (tak ada yang perlu disembunyikan,
 * dan Shopee tidak mengirim ulang tanpa henti), tapi tidak memicu apa pun dan tercatat di log.
 *
 * Shopee menuntut jawaban 2xx dalam beberapa detik: penarikan berjalan SESUDAH respons
 * dikirim (afterResponse), bukan sebelum.
 */
class ShopeePushController extends Controller
{
    /** Kode push chat (webchat_push). */
    public const KODE_CHAT = [10];

    public function __construct(private ShopeeClient $client)
    {
    }

    public function terima(Request $request)
    {
        $body = (string) $request->getContent();
        $data = json_decode($body, true) ?: [];
        $kode = (int) ($data['code'] ?? -1);
        $shopId = (int) ($data['shop_id'] ?? 0);

        $sah = $this->tandaTanganSah($request, $body);

        ShopeeApiLog::create([
            'shop_id'     => $shopId ?: null,
            'method'      => 'PUSH',
            'path'        => '/push/code-' . $kode,
            'http_status' => 200,
            'error'       => $sah ? null : 'tanda_tangan_salah',
            // Isi push disimpan terpotong untuk mempelajari bentuknya (tak memuat token).
            'message'     => mb_substr($body, 0, 1000),
        ]);

        if ($sah && $shopId && $this->pushChat($kode, $body)) {
            $shop = ShopeeShop::where('env', $this->client->env())->where('shop_id', $shopId)->first();

            if ($shop && !$shop->perluHubungkanUlang()) {
                dispatch(fn () => app(ShopeeChatService::class)->tarikDariPush($shop->id))->afterResponse();
            }
        }

        return response('', 200);
    }

    /**
     * Authorization = HMAC-SHA256(kunci, URL push + '|' + body). Kuncinya "Live Push Partner
     * Key" (Console › Push Mechanism › Generate); Partner Key app dicoba sebagai cadangan.
     * URL dicoba dua bentuk — dari APP_URL dan dari permintaan — karena di balik cloudflared
     * skema/host permintaan bisa berbeda dengan yang didaftarkan di Console.
     */
    private function tandaTanganSah(Request $request, string $body): bool
    {
        $diterima = (string) $request->header('Authorization', '');
        $setting  = $this->client->setting();
        $kunci    = array_filter(array_unique([$setting->pushKey(), $setting->partnerKey()]));
        if ($diterima === '' || !$kunci) {
            return false;
        }

        foreach ($kunci as $k) {
            foreach (array_unique([route('shopee.push'), $request->url()]) as $url) {
                if (hash_equals(hash_hmac('sha256', $url . '|' . $body, $k), $diterima)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pushChat(int $kode, string $body): bool
    {
        return in_array($kode, self::KODE_CHAT, true) || str_contains($body, '"conversation_id"');
    }
}
