<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\ChatPesananService;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tombol chat Shopee di kartu Pemrosesan Pesanan mengikuti pola kartu Desain di Meja Kerja:
 * Chat Shopee bila percakapan ada, Mulai Chat Shopee + Seller Centre bila belum.
 */
class FulfillmentChatShopeeTest extends TestCase
{
    use RefreshDatabase;

    private int $nomor = 0;
    private User $cs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cs = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->cs->menuPermissions()->create(['menu_key' => 'crm.inbox']);
        $this->actingAs($this->cs);
    }

    private function so(?string $noMarketplace, bool $link = true): SalesOrder
    {
        $this->nomor++;
        $so = SalesOrder::create(['order_number' => 'SO-' . $this->nomor, 'customer_po_number' => $noMarketplace,
            'customer_id' => Customer::create(['code' => 'C' . $this->nomor, 'name' => 'Shopee', 'is_active' => true, 'is_marketplace' => $link])->id,
            'warehouse_id' => Warehouse::create(['name' => 'Gudang ' . $this->nomor, 'is_sellable' => true, 'is_active' => true])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);
        if ($link) {
            JubelioOrderLink::create(['jubelio_salesorder_id' => 900 + $this->nomor, 'jubelio_salesorder_no' => 'SP-' . $this->nomor,
                'sales_order_id' => $so->id, 'store' => 'Shopee', 'last_status' => 'paid']);
        }
        return $so;
    }

    private function salinanShopee(string $sn, int $pembeli): void
    {
        DB::table('shopee_orders')->insert(['env' => 'live', 'shop_id' => 55, 'order_sn' => $sn, 'buyer_user_id' => $pembeli,
            'status' => 'READY_TO_SHIP', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function chatShopee(int $pembeli, string $terakhir = 'now'): CrmConversation
    {
        $c = CrmConversation::findOrCreateFor((string) $pembeli . random_int(10, 99), CrmConversation::KANAL_SHOPEE, '55');
        DB::table('shopee_chats')->insert(['env' => 'live', 'shop_id' => 55, 'conversation_id' => $c->id, 'to_id' => $pembeli,
            'crm_conversation_id' => $c->id, 'last_message_at' => now()->modify($terakhir), 'created_at' => now(), 'updated_at' => now()]);
        return $c;
    }

    /** Baris sungguhan dari layanan, lalu tombol yang benar-benar dirender kartunya. */
    private function tombol(SalesOrder $so): string
    {
        $m = new \ReflectionMethod(FulfillmentReadinessService::class, 'rowsFor');
        $row = $m->invoke(app(FulfillmentReadinessService::class), collect([$so->fresh()]))->first();

        return view('erp.pos.fulfillment._chat_btn', ['r' => $row])->render();
    }

    public function test_chat_sudah_ada_membuka_chat_shopee_tanpa_mulai_chat(): void
    {
        $so = $this->so('SP-AAA');
        $this->salinanShopee('AAA', 777);
        $this->chatShopee(777, '-2 days');
        $baru = $this->chatShopee(777);

        $html = $this->tombol($so);

        $this->assertStringContainsString('Chat Shopee', $html);
        $this->assertStringContainsString(route('crm.inbox.show', $baru->id), $html, 'Yang terbaru dibuka bila pembeli punya banyak chat.');
        $this->assertStringNotContainsString('Mulai Chat Shopee', $html);
        $this->assertStringNotContainsString('Seller Centre', $html);
    }

    public function test_belum_ada_chat_tampil_mulai_chat_dan_seller_centre(): void
    {
        $so = $this->so('SP-BBB');
        $this->salinanShopee('BBB', 778);

        $html = $this->tombol($so);

        $this->assertStringContainsString('Mulai Chat Shopee', $html);
        $this->assertStringContainsString(route('tasks.meja.chat-shopee', $so->id), $html);
        $this->assertStringContainsString('Seller Centre', $html);
        $this->assertStringContainsString('data-copy="BBB"', $html);
    }

    public function test_id_pembeli_belum_ada_hanya_seller_centre(): void
    {
        $html = $this->tombol($this->so('SP-CCC'));

        $this->assertStringContainsString('Chat di Seller Centre', $html);
        $this->assertStringNotContainsString('Mulai Chat Shopee', $html);
    }

    public function test_pesanan_shopee_tertaut_wa_dapat_tombol_keduanya(): void
    {
        $so = $this->so('SP-DDD');
        $this->salinanShopee('DDD', 779);
        $sp = $this->chatShopee(779);
        $wa = CrmConversation::findOrCreateFor('628111222333');
        DB::table('crm_pesanan_chat')->insert(['conversation_id' => $wa->id, 'sales_order_id' => $so->id, 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->tombol($so);

        $this->assertStringContainsString(route('crm.inbox.show', $sp->id), $html);
        $this->assertStringContainsString(route('crm.inbox.show', $wa->id), $html);
        $this->assertStringContainsString('Chat WA', $html);
    }

    public function test_marketplace_lain_dan_pesanan_biasa_tidak_berubah(): void
    {
        // Tokopedia belum tertaut → tanpa tombol; tertaut → 💬 Chat lama.
        $tokped = $this->so('TP-123');
        $this->assertStringNotContainsString('Chat', $this->tombol($tokped));
        $wa = CrmConversation::findOrCreateFor('628111222444');
        DB::table('crm_pesanan_chat')->insert(['conversation_id' => $wa->id, 'sales_order_id' => $tokped->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringContainsString(route('crm.inbox.chat-pesanan', $tokped->id), $this->tombol($tokped));

        // SO biasa yang nomor PO-nya kebetulan berawalan SP- tetap chat WhatsApp biasa.
        $biasa = $this->so('SP-PO-KANTOR', false);
        $html = $this->tombol($biasa);
        $this->assertStringContainsString(route('crm.inbox.chat-pesanan', $biasa->id), $html);
        $this->assertStringNotContainsString('Shopee', $html);
    }

    public function test_tanpa_akses_inbox_tombol_tidak_tampil_dan_sapa_ditolak(): void
    {
        $so = $this->so('SP-EEE');
        $this->salinanShopee('EEE', 780);

        $orangLain = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($orangLain);

        $this->assertStringNotContainsString('Chat', $this->tombol($so));
        $this->post(route('tasks.meja.chat-shopee', $so->id), ['teks' => 'x'])->assertForbidden();
    }

    public function test_pemegang_inbox_boleh_memakai_jalur_sapa(): void
    {
        $so = $this->so('SP-FFF');

        // Lolos gerbang izin; berhenti di layanan karena salinan pesanannya memang belum ada.
        $this->post(route('tasks.meja.chat-shopee', $so->id), ['teks' => 'Halo kak'])
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_query_tidak_bertambah_per_kartu(): void
    {
        $no = [];
        foreach (['G1', 'G2', 'G3'] as $i => $sn) {
            $no[$this->so('SP-' . $sn)->id] = 'SP-' . $sn;
            $this->salinanShopee($sn, 800 + $i);
        }

        DB::enableQueryLog();
        $hasil = app(ChatPesananService::class)->untuk($no);
        $this->assertCount(3, DB::getQueryLog(), 'Tautan, salinan pesanan, chat — sekali untuk semua kartu.');
        DB::disableQueryLog();
        $this->assertCount(3, array_filter(array_column($hasil, 'bisa_sapa')));
    }
}
