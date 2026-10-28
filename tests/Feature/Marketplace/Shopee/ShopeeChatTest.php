<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Providers\ShopeeChatProvider;
use App\Modules\Marketplace\Shopee\Models\ShopeeChat;
use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chat Shopee → CRM Tahap A (baca saja): AI-saja disembunyikan, pesan disalin dengan sumber
 * yang benar, SO tertaut dari kartu pesanan, dan tak satu pun kiriman lewat jalur WhatsApp.
 */
class ShopeeChatTest extends TestCase
{
    use RefreshDatabase;

    private const TOKO = 555;

    private ShopeeShop $shop;
    private User $saiful;

    /** conversation_id => daftar pesan (terbaru dulu, seperti API) */
    private array $pesan = [];
    private array $percakapan = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
        $this->shop = ShopeeShop::create([
            'shop_id' => self::TOKO, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        $this->saiful = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Saiful']);
        ShopeeSetting::singleton()->forceFill(['chat_owner_user_id' => $this->saiful->id])->save();

        Http::fake(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (str_contains($r->url(), 'get_conversation_list')) {
                return Http::response(['response' => ['conversations' => array_values($this->percakapan), 'page_result' => ['more' => false]]]);
            }
            if (str_contains($r->url(), 'get_message')) {
                return Http::response(['response' => ['messages' => $this->pesan[$q['conversation_id']] ?? [], 'page_result' => ['next_offset' => '']]]);
            }

            return Http::response(['error' => 'tak_terduga'], 400);
        });
    }

    private function cv(int $id, string $nama, int $detik, string $terakhir): void
    {
        $this->percakapan[$id] = [
            'conversation_id' => $id, 'to_id' => $id * 10, 'to_name' => $nama,
            'last_message_timestamp' => $detik . '000000000', 'latest_message_id' => $terakhir,
        ];
    }

    private function m(string $id, string $tipe, array $isi, bool $toko = false, string $sumber = 'android', ?int $detik = null): array
    {
        return [
            'message_id' => $id, 'message_type' => $tipe, 'content' => $isi,
            'from_shop_id' => $toko ? self::TOKO : 999, 'source' => $sumber,
            'created_timestamp' => $detik ?? now()->timestamp,
        ];
    }

    public function test_ai_saja_disembunyikan_dan_chat_penjual_disalin_lengkap(): void
    {
        $t = now()->subMinutes(10)->timestamp;
        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $mp = Customer::create(['code' => 'MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
        $so = SalesOrder::create(['order_number' => 'SP-261027PDYVRSV7', 'customer_po_number' => 'SP-261027PDYVRSV7', 'customer_id' => $mp,
            'warehouse_id' => $gudang, 'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);

        // Percakapan 1: hanya AI Shopee + sambutan otomatis toko → disembunyikan.
        $this->cv(1, 'ai_saja', $t, 'a2');
        $this->pesan[1] = [
            $this->m('a2', 'text', ['text' => 'Selamat datang'], toko: true, sumber: 'server', detik: $t),
            $this->m('a1', 'bundle_message', ['messages' => ['x', 'y']], detik: $t - 5),
        ];

        // Percakapan 2: dialihkan AI → pembeli tanya + kartu pesanan → admin balas → pembeli tanya lagi.
        $this->cv(2, 'fafastoreo', $t, 'b6');
        $this->pesan[2] = [
            $this->m('b6', 'text', ['text' => 'kapan dikirim kak?'], detik: $t),
            $this->m('b5', 'text', ['text' => 'Hi! Pesanmu telah kami terima'], toko: true, sumber: 'server', detik: $t - 10),
            $this->m('b4', 'text', ['text' => 'baik kak'], toko: true, sumber: 'new_webchat', detik: $t - 20),
            $this->m('b3', 'order', ['order_sn' => '261027PDYVRSV7', 'shop_id' => self::TOKO], detik: $t - 30),
            $this->m('b2', 'bot_summary_bundle_msg', ['transfer_reason' => ['text' => 'Pembeli tanyakan ukuran'], 'summary' => ['text' => 'Ringkas'], 'suggested_solution' => ['text' => 'Konfirmasi ukuran']], detik: $t - 40),
            $this->m('b1', 'bundle_message', ['messages' => ['p', 'q']], detik: $t - 50),
        ];

        $hasil = app(ShopeeChatService::class)->tarikBaru($this->shop);
        $this->assertSame(['diperiksa' => 2, 'tampil' => 1, 'tersembunyi' => 1, 'pesan' => 5], $hasil);

        $this->assertFalse(ShopeeChat::where('conversation_id', 1)->value('tampil'));
        $this->assertSame(1, CrmConversation::count());

        $crm = CrmConversation::first();
        $this->assertSame(CrmConversation::KANAL_SHOPEE, $crm->channel);
        $this->assertSame('fafastoreo', $crm->display_name);
        $this->assertSame($this->saiful->id, $crm->owner_user_id);
        $this->assertSame(CrmConversation::STATUS_AKTIF, $crm->status);
        $this->assertSame(1, $crm->unread_count, 'hanya pertanyaan sesudah balasan admin yang belum dibaca; balasan otomatis tidak dihitung');

        $pesan = CrmMessage::where('conversation_id', $crm->id)->orderBy('sent_at')->get();
        $this->assertCount(5, $pesan, 'penanda obrolan AI tidak disalin');
        $this->assertStringContainsString('Dialihkan dari AI Shopee', $pesan[0]->content);
        $this->assertStringContainsString('Alasan: Pembeli tanyakan ukuran', $pesan[0]->content);
        $this->assertSame('🧾 Pesanan Shopee 261027PDYVRSV7', $pesan[1]->content);
        $this->assertSame(CrmMessage::SOURCE_SELLER_CENTRE, $pesan[2]->source);
        $this->assertSame(CrmMessage::SOURCE_SHOPEE_OTOMATIS, $pesan[3]->source);
        $this->assertSame(CrmMessage::MASUK, $pesan[4]->direction);

        $this->assertSame($crm->id, (int) DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->value('conversation_id'));
    }

    public function test_sinkron_ulang_hanya_menyalin_pesan_baru_dan_ai_jadi_tampil_saat_pembeli_minta_penjual(): void
    {
        $t = now()->subMinutes(5)->timestamp;
        $this->cv(3, 'budi', $t, 'c1');
        $this->pesan[3] = [$this->m('c1', 'bundle_message', ['messages' => ['z']], detik: $t)];

        $svc = app(ShopeeChatService::class);
        $svc->tarikBaru($this->shop);
        $this->assertSame(0, CrmConversation::count());

        // Tidak berubah → dilewati.
        $this->assertSame(0, $svc->tarikBaru($this->shop)['diperiksa']);

        // Pembeli menekan "Chat dengan Penjual" lalu bertanya.
        $this->cv(3, 'budi', $t + 60, 'c3');
        array_unshift($this->pesan[3],
            $this->m('c3', 'text', ['text' => 'ukuran 20x30 ada?'], detik: $t + 60),
            $this->m('c2', 'faq_liveagent', ['text' => 'Chat dengan Penjual'], detik: $t + 30));
        $this->shop->refresh();
        $h = $svc->tarikBaru($this->shop);

        $this->assertSame(1, $h['tampil']);
        $crm = CrmConversation::firstOrFail();
        $this->assertSame(2, CrmMessage::where('conversation_id', $crm->id)->count());
        $this->assertSame(2, $crm->unread_count);

        // Admin membalas dari Seller Centre → belum dibaca jadi 0, pesan lama tidak dobel.
        $this->cv(3, 'budi', $t + 120, 'c4');
        array_unshift($this->pesan[3], $this->m('c4', 'text', ['text' => 'ada kak'], toko: true, sumber: 'new_webchat', detik: $t + 120));
        $this->shop->refresh();
        $svc->tarikBaru($this->shop);

        $crm->refresh();
        $this->assertSame(3, CrmMessage::where('conversation_id', $crm->id)->count());
        $this->assertSame(0, $crm->unread_count);
    }

    public function test_riwayat_masuk_sebagai_arsip_tanpa_belum_dibaca(): void
    {
        $t = now()->subDays(40)->timestamp;
        $this->cv(4, 'lama', $t, 'd1');
        $this->pesan[4] = [$this->m('d1', 'image', ['url' => 'https://cf.shopee.co.id/file/abc'], detik: $t)];

        $h = app(ShopeeChatService::class)->tarikRiwayat($this->shop);

        $this->assertTrue($h['selesai']);
        $crm = CrmConversation::firstOrFail();
        $this->assertSame(CrmConversation::STATUS_ARSIP, $crm->status);
        $this->assertSame(0, $crm->unread_count);
        $lampiran = CrmMessage::firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('https://cf.shopee.co.id/file/abc', $lampiran->source_url);
        $this->assertNotNull($lampiran->download_error, 'riwayat tidak ikut antrean unduh otomatis');
        $this->assertNotNull($this->shop->fresh()->chat_riwayat_selesai_at);
    }

    public function test_chat_shopee_tidak_pernah_dikirim_lewat_whatsapp(): void
    {
        $crm = CrmConversation::findOrCreateFor('9990', CrmConversation::KANAL_SHOPEE, (string) self::TOKO);

        config(['crm.dry_run' => false]);
        $jalur = app(ChatManager::class)->untuk($crm);
        $this->assertInstanceOf(ShopeeChatProvider::class, $jalur);
        $this->assertFalse($jalur->sendText(['to' => '9990', 'text' => 'halo'])['success']);
        $this->assertFalse(app(ChatManager::class)->siapUntuk($crm));

        config(['crm.dry_run' => true]);
        $this->assertInstanceOf(ShopeeChatProvider::class, app(ChatManager::class)->untuk($crm));
        $this->assertTrue($crm->tanpaJendela());
    }

    public function test_inbox_menampilkan_chat_shopee_baca_saja_tanpa_tombol_mulai_chat(): void
    {
        $t = now()->subMinutes(3)->timestamp;
        $this->cv(5, 'pembeli_shopee', $t, 'e2');
        $this->pesan[5] = [
            $this->m('e2', 'text', ['text' => 'baik kak'], toko: true, sumber: 'new_webchat', detik: $t),
            $this->m('e1', 'text', ['text' => 'halo kak'], detik: $t - 10),
        ];
        app(ShopeeChatService::class)->tarikBaru($this->shop);
        $crm = CrmConversation::firstOrFail();

        $this->actingAs($this->saiful)->get(route('crm.inbox.show', $crm))
            ->assertOk()
            ->assertSee('Chat Shopee — baca saja')
            ->assertSee('Seller Centre')
            ->assertSee('Shopee · pembeli_shopee')
            ->assertDontSee('Pesan bebas tidak bisa dikirim');
    }
}
