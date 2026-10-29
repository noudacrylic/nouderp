<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Marketplace\Shopee\Models\ShopeeApiLog;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chat Shopee Tahap C — Live Push: push bertanda tangan sah memicu penarikan chat seketika;
 * yang salah tanda tangan / bukan chat tidak memicu apa pun, dan semuanya tercatat.
 */
class ShopeePushTest extends TestCase
{
    use RefreshDatabase;

    private const TOKO = 555;
    private const KUNCI = 'kunci-rahasia-uji';

    private int $panggilDaftar = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => self::KUNCI, 'env' => 'test']]);
        ShopeeShop::create([
            'shop_id' => self::TOKO, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);

        Http::fake(function (Request $r) {
            $t = now()->subMinute()->timestamp;
            if (str_contains($r->url(), 'get_conversation_list')) {
                $this->panggilDaftar++;

                return Http::response(['response' => ['conversations' => [[
                    'conversation_id' => 9, 'to_id' => 90, 'to_name' => 'pembeli_push',
                    'last_message_timestamp' => $t . '000000000', 'latest_message_id' => 'p1',
                ]], 'page_result' => ['more' => false]]]);
            }
            if (str_contains($r->url(), 'get_message')) {
                return Http::response(['response' => ['messages' => [[
                    'message_id' => 'p1', 'message_type' => 'text', 'content' => ['text' => 'kak masih ada?'],
                    'from_shop_id' => 0, 'source' => 'android', 'created_timestamp' => $t,
                ]], 'page_result' => ['next_offset' => '']]]);
            }

            return Http::response(['error' => 'tak_terduga'], 400);
        });
    }

    private function push(array $isi, ?string $tandaTangan = null)
    {
        $body = json_encode($isi);
        $sah  = hash_hmac('sha256', route('shopee.push') . '|' . $body, self::KUNCI);

        return $this->call('POST', route('shopee.push'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $tandaTangan ?? $sah,
        ], $body);
    }

    public function test_push_chat_sah_langsung_menarik_chat_ke_crm(): void
    {
        $this->push(['shop_id' => self::TOKO, 'code' => 10, 'timestamp' => time(), 'data' => ['type' => 'message']])
            ->assertOk();

        $this->assertSame(1, $this->panggilDaftar);
        $this->assertSame('kak masih ada?', CrmMessage::sole()->content);

        $log = ShopeeApiLog::where('method', 'PUSH')->sole();
        $this->assertSame('/push/code-10', $log->path);
        $this->assertNull($log->error);
    }

    public function test_tanda_tangan_salah_dijawab_200_tapi_tidak_memicu_apa_pun(): void
    {
        $this->push(['shop_id' => self::TOKO, 'code' => 10], 'palsu')->assertOk();

        $this->assertSame(0, $this->panggilDaftar);
        $this->assertSame('tanda_tangan_salah', ShopeeApiLog::where('method', 'PUSH')->sole()->error);
    }

    public function test_push_bukan_chat_dan_toko_asing_tidak_menarik_chat(): void
    {
        $this->push(['shop_id' => self::TOKO, 'code' => 3, 'data' => ['ordersn' => 'X', 'status' => 'READY_TO_SHIP']])->assertOk();
        $this->push(['shop_id' => 999, 'code' => 10])->assertOk();

        $this->assertSame(0, $this->panggilDaftar);
        $this->assertSame(2, ShopeeApiLog::where('method', 'PUSH')->count());
    }

    public function test_push_beruntun_saat_penarikan_berjalan_hanya_menitip_ulang(): void
    {
        $shop = ShopeeShop::sole();
        $kunci = Cache::lock('shopee-chat-push:' . $shop->id, 120);
        $kunci->get();

        app(ShopeeChatService::class)->tarikDariPush($shop->id);

        $this->assertSame(0, $this->panggilDaftar);
        $this->assertTrue(Cache::get('shopee-chat-push-ulang:' . $shop->id));
        $kunci->release();
    }

    public function test_halaman_pengaturan_menampilkan_url_push(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('settings.integrations.shopee.index'))
            ->assertOk()
            ->assertSee(route('shopee.push'))
            ->assertSee('belum pernah diterima');
    }
}
