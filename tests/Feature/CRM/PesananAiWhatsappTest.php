<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Assistant\Services\FakeClaudeClient;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alat `buat_pesanan` agen AI WhatsApp: pagar di kode, SO draft baru lahir saat admin klik
 * "Buat pesanan" (mode saran) atau saat balasan terkirim (mode Otomatis).
 */
class PesananAiWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true, 'crm.agen.saran_aktif' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');
        $this->claude = new FakeClaudeClient();
        $this->app->instance(ClaudeClient::class, $this->claude);
        CrmAgent::where('kode', CrmAgent::CS_WHATSAPP)->update(['is_active' => true]);

        $gudang = Warehouse::create(['name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true]);
        $this->produk = Product::create(['sku' => 'KS-3K', 'name' => 'Kotak Saran 3 Kotak', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 0, 'is_active' => true, 'is_sellable' => true, 'weight_gram' => 800]);
        DB::table('product_prices')->insert(['product_id' => $this->produk->id, 'unit_name' => 'pcs', 'channel' => 'default',
            'price' => 150000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        ProductStock::create(['product_id' => $this->produk->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => 5]);
    }

    private function chat(string $isi = 'ya kak betul, order'): CrmConversation
    {
        $c = CrmConversation::findOrCreateFor('6281234567890', CrmConversation::KANAL_CERMIN);
        CrmMessage::create(['conversation_id' => $c->id, 'direction' => CrmMessage::MASUK, 'message_type' => 'text',
            'content' => $isi, 'sent_at' => now()->subMinutes(2)]);
        $c->forceFill(['last_inbound_at' => null, 'last_message_at' => now()->subMinutes(2)])->save();

        return $c;
    }

    private function siapkan(array $input, CrmConversation $c): array
    {
        return app(\App\Modules\CRM\Services\PesananAiService::class)->siapkan($input, $c);
    }

    public function test_pagar_nama_stok_preorder_dan_batas_total(): void
    {
        $c = $this->chat();
        $dasar = ['pengiriman' => 'ambil', 'barang' => [['sku' => 'KS-3K', 'jumlah' => 2]]];

        $this->assertStringContainsString('Nama pemesan', $this->siapkan($dasar, $c)['pesan']);
        $this->assertStringContainsString('Stok Kotak Saran 3 Kotak tinggal 5', $this->siapkan(['nama_pemesan' => 'Budi', 'barang' => [['sku' => 'KS-3K', 'jumlah' => 9]]] + $dasar, $c)['pesan']);

        $ok = $this->siapkan(['nama_pemesan' => 'Budi'] + $dasar, $c);
        $this->assertTrue($ok['ok']);
        $this->assertStringContainsString('Kotak Saran 3 Kotak × 2 @ Rp150.000 = Rp300.000', $ok['ringkasan']);
        $this->assertStringContainsString('Total: Rp300.000', $ok['ringkasan']);

        DB::table('product_prices')->update(['price' => 450000]);
        $this->assertStringContainsString('melebihi batas pesanan AI', $this->siapkan(['nama_pemesan' => 'Budi', 'barang' => [['sku' => 'KS-3K', 'jumlah' => 5]]] + $dasar, $c)['pesan']);

        $this->produk->forceFill(['sale_type' => 'preorder', 'made_to_order' => true])->save();
        $this->assertStringContainsString('bukan produk ready', $this->siapkan(['nama_pemesan' => 'Budi'] + $dasar, $c)['pesan']);
        $this->assertSame(0, SalesOrder::count(), 'Menyiapkan tidak pernah membuat SO.');
    }

    public function test_mode_saran_pesanan_dibuat_saat_admin_klik_dan_tertaut_ke_chat(): void
    {
        $c = $this->chat();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $c->forceFill(['owner_user_id' => $admin->id])->save();
        $this->claude->antrekan(FakeClaudeClient::panggilAlat('buat_pesanan', ['nama_pemesan' => 'Budi Santoso', 'pengiriman' => 'ambil',
                'barang' => [['sku' => 'KS-3K', 'jumlah' => 2]]]))
            ->antrekan(FakeClaudeClient::panggilAlat('usulkan_balasan', ['butir' => 'K1', 'teks' => 'baik kak, ini rinciannya ya 🙏',
                'lempar_ke' => 'tidak', 'alasan' => 'uji']));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $s = CrmSaranAi::firstOrFail();
        $this->assertSame('Budi Santoso', $s->pesanan['nama_pemesan']);
        $this->assertStringContainsString('Total: Rp300.000', $s->pesanan_ringkasan);
        $this->assertSame(0, SalesOrder::count(), 'Mode saran: SO belum dibuat.');

        $json = $this->actingAs($admin)->getJson(route('crm.inbox.saran-ai', $c))->json('saran');
        $this->assertStringContainsString('Total: Rp300.000', $json['pesanan']);

        $hasil = $this->actingAs($admin)->postJson($json['pesanan_url'])->assertOk()->json();
        $so = SalesOrder::firstOrFail();
        $this->assertSame('draft', $so->status instanceof \BackedEnum ? $so->status->value : $so->status);
        $this->assertSame('ambil_toko', $so->delivery_method);
        $this->assertEquals(300000, (float) $so->grand_total);
        $this->assertStringStartsWith('baik kak, ini rinciannya ya 🙏', $hasil['teks']);
        $this->assertStringContainsString($so->order_number, $hasil['teks']);
        $this->assertTrue(DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->where('conversation_id', $c->id)->exists());
        $this->assertNotNull($c->fresh()->customer_id, 'Pelanggan baru dibuat dari nama pemesan.');

        $this->actingAs($admin)->postJson($json['pesanan_url'])->assertOk();
        $this->assertSame(1, SalesOrder::count(), 'Klik kedua memakai SO yang sama.');
    }

    public function test_mode_otomatis_luar_jam_mengirim_rincian_dan_tautan_bayar(): void
    {
        $this->travelTo(now()->next('Monday')->setTime(22, 0));
        CrmAgent::where('kode', CrmAgent::CS_WHATSAPP)->update(['kirim_otomatis' => CrmAgent::KIRIM_OTOMATIS]);
        $c = $this->chat();
        $this->claude->antrekan(FakeClaudeClient::panggilAlat('buat_pesanan', ['nama_pemesan' => 'Budi', 'pengiriman' => 'ambil',
                'barang' => [['sku' => 'KS-3K', 'jumlah' => 1]]]))
            ->antrekan(FakeClaudeClient::panggilAlat('usulkan_balasan', ['butir' => 'K1', 'teks' => '',
                'lempar_ke' => 'tidak', 'alasan' => 'uji']));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $so = SalesOrder::firstOrFail();
        $kirim = CrmMessage::where('source', CrmMessage::SOURCE_AI)->value('content');
        $this->assertStringStartsWith('baik kak, ini rincian pesanan & tautan pembayarannya ya 🙏', $kirim, 'Teks pengantar cadangan.');
        $this->assertStringContainsString($so->order_number, $kirim);
        $this->assertStringContainsString('Total: Rp 150.000', $kirim);
    }

    public function test_kurir_reguler_ongkir_ditarik_ulang_dan_alamat_tersimpan(): void
    {
        $penyedia = \Mockery::mock(\App\Modules\Shipping\Contracts\ShippingProvider::class);
        $penyedia->shouldReceive('searchAreas')->andReturnUsing(fn ($q) => ['areas' => mb_strtolower($q) === 'tembalang' ? [
            ['id' => 'A1', 'postal_code' => '50275', 'subdistrict' => 'Tembalang', 'district' => 'Tembalang', 'city' => 'KOTA SEMARANG', 'province' => 'JAWA TENGAH'],
        ] : []]);
        $this->mock(\App\Modules\Shipping\ShippingManager::class, function ($m) use ($penyedia) {
            $m->shouldReceive('defaultProviderKey')->andReturn('jubelio_shipment');
            $m->shouldReceive('provider')->andReturn($penyedia);
            $m->shouldReceive('rates')->andReturn(['rates' => [
                ['provider' => 'jubelio_shipment', 'courier_code' => '7', 'service_code' => '71', 'courier_name' => 'J&T', 'service_name' => 'EZ', 'price' => 32000],
            ], 'errors' => []]);
        });
        $this->mock(\App\Modules\Sales\Services\PromotionService::class, function ($m) {
            $m->shouldReceive('resolveItemDiscounts')->andReturn([]);
            $m->shouldReceive('resolveCartTotalDiscount')->andReturn(null);
            $m->shouldReceive('resolveShippingDiscount')->andReturn(['discount_amount' => 25000]);
        });
        $c = $this->chat();
        $input = ['nama_pemesan' => 'Budi', 'pengiriman' => 'kurir', 'tujuan' => 'Tembalang Semarang',
            'alamat' => 'Jl. Banjarsari No. 12 RT 3 RW 4', 'barang' => [['sku' => 'KS-3K', 'jumlah' => 1]]];

        $this->assertStringContainsString('Yang tersedia: J&T EZ', $this->siapkan($input + ['kurir' => 'JNE REG'], $c)['pesan']);

        $ok = $this->siapkan($input + ['kurir' => 'j&t ez'], $c);
        $this->assertTrue($ok['ok'], $ok['pesan']);
        $this->assertStringContainsString('Ongkir J&T EZ: Rp32.000 − potongan ongkir Rp25.000 = Rp7.000', $ok['ringkasan']);
        $this->assertStringContainsString('Total: Rp157.000', $ok['ringkasan']);
        $this->assertSame(['jubelio_shipment', '7', '71'], [$ok['data']['shipping_provider'], $ok['data']['shipping_courier_code'], $ok['data']['shipping_service_code']]);

        $so = app(\App\Modules\CRM\Services\PesananChatService::class)->buat($c, $ok['data']);
        $this->assertEquals(157000, (float) $so->grand_total);
        $this->assertSame('Jl. Banjarsari No. 12 RT 3 RW 4', $so->customer->shipping_address);
        $this->assertSame('50275', $so->customer->postal_code);
    }
}
