<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Assistant\Services\FakeClaudeClient;
use App\Modules\CRM\Agents\AgenPrompt;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use App\Modules\CRM\Services\SaranAiService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saran AI untuk nomor utama WhatsApp (agen cs-whatsapp, mode saran, 16 Nov 2026):
 * sambutan otomatis WhatsApp Business bukan jawaban, tidak ada yang dikirim, chat tidak dioper.
 */
class SaranAiWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    private const SAMBUTAN = "Halo 👋\nTerima kasih sudah menghubungi Noud Acrylic Shop 😊\n📌 Katalog produk lengkap kami bisa dilihat di noudakrilik.com";

    protected function setUp(): void
    {
        parent::setUp();
        $this->claude = new FakeClaudeClient();
        $this->app->instance(ClaudeClient::class, $this->claude);
        config(['crm.agen.saran_aktif' => true, 'crm.dry_run' => true]);
        // Agen lahir mati (sama seperti agen lain); owner menyalakannya dari layar Agen.
        CrmAgent::where('kode', CrmAgent::CS_WHATSAPP)->update(['is_active' => true]);
    }

    /** Chat WA baru: pesan pembeli lalu sambutan otomatis dari aplikasi HP 2 detik kemudian. */
    private function chat(string $isi = 'kak alamatnya dimana?'): CrmConversation
    {
        $c = CrmConversation::findOrCreateFor('6281234567890', CrmConversation::KANAL_CERMIN);
        $this->pesan($c, CrmMessage::MASUK, $isi, now()->subMinutes(2));
        $this->pesan($c, CrmMessage::KELUAR, self::SAMBUTAN, now()->subMinutes(2)->addSeconds(2), CrmMessage::SOURCE_WHATSAPP_APP);
        // Seperti data WAHA sungguhan: last_inbound_at TIDAK diisi kanal ini (hanya kanal resmi/Shopee).
        $c->forceFill(['last_inbound_at' => null, 'last_outbound_at' => now()->subMinutes(2)->addSeconds(2),
            'last_message_at' => now()->subMinutes(2)->addSeconds(2)])->save();

        return $c;
    }

    private function pesan(CrmConversation $c, string $arah, string $isi, $waktu, ?string $sumber = null): CrmMessage
    {
        return CrmMessage::create(['conversation_id' => $c->id, 'direction' => $arah, 'message_type' => 'text',
            'content' => $isi, 'source' => $sumber, 'sent_at' => $waktu]);
    }

    private function usul(string $butir, string $teks, string $lempar = 'tidak'): array
    {
        return FakeClaudeClient::panggilAlat('usulkan_balasan', ['butir' => $butir, 'teks' => $teks, 'lempar_ke' => $lempar, 'alasan' => 'uji']);
    }

    public function test_saran_dibuat_untuk_chat_wa_walau_sambutan_otomatis_sudah_terkirim(): void
    {
        $c = $this->chat();
        $this->claude->antrekan($this->usul('A1', 'Jl. Suren Raya No.25A, Padangsari, Banyumanik, Semarang ya kak'));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $s = CrmSaranAi::firstOrFail();
        $this->assertSame([$c->id, 'A1', CrmSaranAi::BARU], [$s->conversation_id, $s->butir, $s->status]);
        $this->assertSame(CrmAgent::CS_WHATSAPP, $s->run->agent->kode);

        $muatan = $this->claude->diterima[0];
        $this->assertSame(['cek_stok', 'info_pesanan', 'deskripsi_produk', 'cek_ongkir', 'buat_pesanan', 'usulkan_balasan'], array_column($muatan['tools'], 'name'));
        $this->assertStringContainsString('Bukan pelanggan', $muatan['system'][0]['text'], 'Pengetahuan = daftar tanya-jawab WA.');
        $this->assertStringContainsString('chat WHATSAPP', $muatan['system'][0]['text']);
        $this->assertStringNotContainsString('DRAF', $muatan['system'][0]['text']);
        $this->assertStringContainsString('Z2', json_encode($muatan['tools'][5], JSON_UNESCAPED_UNICODE), 'Kode butir WA, bukan I1/I2 Shopee.');

        $this->assertNotNull(app(SaranAiService::class)->berlaku($c->refresh()), 'Sambutan sesudah pesan tidak membuat saran basi.');
        $this->assertSame(1, CrmMessage::where('direction', CrmMessage::KELUAR)->count(), 'Mode saran: tidak ada yang dikirim.');
    }

    public function test_chat_wa_yang_sudah_dibalas_admin_dari_hp_tidak_diberi_saran(): void
    {
        $c = $this->chat();
        $this->pesan($c, CrmMessage::KELUAR, 'Baik kak', now()->subMinute(), CrmMessage::SOURCE_WHATSAPP_APP);

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $this->assertSame(0, CrmSaranAi::count());
        $this->assertCount(0, $this->claude->diterima);
    }

    public function test_saran_basi_setelah_admin_membalas_dari_hp(): void
    {
        $c = $this->chat();
        $this->claude->antrekan($this->usul('A1', 'alamat kak'));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $this->pesan($c, CrmMessage::KELUAR, 'Jl. Suren Raya 25A kak', now(), CrmMessage::SOURCE_WHATSAPP_APP);

        $this->assertNull(app(SaranAiService::class)->berlaku($c->refresh()));
        $this->assertSame(CrmSaranAi::KEDALUWARSA, CrmSaranAi::firstOrFail()->status);
    }

    public function test_agen_wa_nonaktif_tidak_menyarankan_wa_tapi_shopee_tetap(): void
    {
        $this->chat();
        CrmAgent::where('kode', CrmAgent::CS_WHATSAPP)->update(['is_active' => false]);

        $this->assertSame([CrmConversation::KANAL_SHOPEE], app(SaranAiService::class)->kanalAktif());
        $this->artisan('crm:saran-ai')->assertSuccessful();
        $this->assertSame(0, CrmSaranAi::count());
    }

    public function test_lemparan_wa_dioper_per_peran_dan_mode_mati_tidak_mengirim(): void
    {
        $this->travelTo(now()->next('Monday')->setTime(22, 0));
        $desain = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $desain->id, 'urutan' => 1]);
        $c = $this->chat('kak logonya mau diganti');
        $this->claude->antrekan($this->usul('C3', 'saya teruskan ke admin desain ya kak', 'desain'));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $this->assertSame($desain->id, (int) $c->refresh()->owner_user_id, 'WA dioper seperti Shopee.');
        $this->assertSame(CrmSaranAi::TAK_PERLU, CrmSaranAi::firstOrFail()->status, 'Lemparan ke chat yang sudah dipegang: tanpa saran.');
        $this->assertSame(1, CrmMessage::where('direction', CrmMessage::KELUAR)->count(), 'Mode Mati: tidak mengirim.');
    }

    public function test_wa_mode_otomatis_luar_jam_chat_dipegang_diberi_info_admin_aktif(): void
    {
        $this->travelTo(now()->next('Monday')->setTime(22, 0));
        CrmAgent::where('kode', CrmAgent::CS_WHATSAPP)->update(['kirim_otomatis' => CrmAgent::KIRIM_OTOMATIS]);
        $pemegang = User::factory()->create();
        $c = $this->chat('kak bisa custom kotak 20x30?');
        $c->forceFill(['owner_user_id' => $pemegang->id])->save();
        $this->claude->antrekan($this->usul('D1', 'saya teruskan ke admin produksi ya kak', 'owner'));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $ai = CrmMessage::where('source', CrmMessage::SOURCE_AI)->pluck('content')->all();
        $this->assertCount(1, $ai);
        $this->assertStringContainsString('admin aktif lagi', $ai[0]);
        $this->assertStringNotContainsString('teruskan', $ai[0], 'Tidak melempar lagi, cukup info.');
    }

    public function test_info_pesanan_wa_membaca_so_pelanggan(): void
    {
        $c = $this->chat('kak pesanan saya sudah jadi?');
        $cust = Customer::create(['code' => 'CUST-WA1', 'name' => 'Budi', 'is_marketplace' => false, 'is_active' => true]);
        $c->forceFill(['customer_id' => $cust->id])->save();
        SalesOrder::create(['order_number' => 'SO-WA-0001', 'customer_id' => $cust->id, 'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Uji WA'])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'grand_total' => 150000, 'paid_amount' => 0, 'delivery_method' => 'ambil_toko']);

        $this->claude->antrekan(FakeClaudeClient::panggilAlat('info_pesanan', []))
            ->antrekan($this->usul('A4', 'masih menunggu pembayaran kak'));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $hasil = CrmSaranAi::firstOrFail()->run->jejak[0]['hasil'];
        $this->assertStringContainsString('SO-WA-0001', $hasil);
        $this->assertStringContainsString('diambil di toko', $hasil);
        $this->assertStringContainsString('sisa Rp150.000', $hasil);
    }

    public function test_cek_ongkir_wa_memakai_wilayah_tarif_dan_potongan_ongkir(): void
    {
        $p = \App\Core\Inventory\Product::create(['sku' => 'KS-3K', 'name' => 'Kotak Saran 3 Kotak', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true, 'weight_gram' => 800]);
        $penyedia = \Mockery::mock(\App\Modules\Shipping\Contracts\ShippingProvider::class);
        $penyedia->shouldReceive('searchAreas')->with('Tembalang Semarang')->andReturn(['areas' => [
            ['id' => 'A1', 'postal_code' => '50275', 'subdistrict' => 'Tembalang', 'district' => 'Tembalang', 'city' => 'Kota Semarang', 'province' => 'Jawa Tengah'],
        ]]);
        $this->mock(\App\Modules\Shipping\ShippingManager::class, function ($m) use ($penyedia) {
            $m->shouldReceive('defaultProviderKey')->andReturn('jubelio_shipment');
            $m->shouldReceive('provider')->andReturn($penyedia);
            $m->shouldReceive('rates')->withArgs(fn ($b) => $b['destination_area_id'] === 'A1' && $b['destination_postal_code'] === '50275'
                && $b['items'][0]['quantity'] === 2)
                ->andReturn(['rates' => [
                    ['courier_name' => 'J&T', 'service_name' => 'EZ', 'price' => 18000, 'etd' => '1-2 hari'],
                    ['courier_name' => 'JNE', 'service_name' => 'REG', 'price' => 21000, 'etd' => '2-3 hari'],
                ], 'errors' => []]);
        });
        $this->mock(\App\Modules\Sales\Services\PromotionService::class, function ($m) {
            $m->shouldReceive('resolveItemDiscounts')->andReturn([]);
            $m->shouldReceive('resolveShippingDiscount')->andReturn(['discount_amount' => 20000]);
        });

        $hasil = app(\App\Modules\CRM\Agents\AgenTools::class)->jalankan('cek_ongkir',
            ['tujuan' => 'Tembalang Semarang', 'barang' => [['sku' => 'ks-3k', 'jumlah' => 2]]], null, CrmAgent::kode(CrmAgent::CS_WHATSAPP))['content'];

        $this->assertStringContainsString('Tembalang, Tembalang, Kota Semarang, Jawa Tengah 50275', $hasil);
        $this->assertStringContainsString('J&T EZ: Rp18.000, potongan ongkir Rp18.000 → dibayar Rp0', $hasil, 'Potongan tak melebihi ongkir.');
        $this->assertStringContainsString('JNE REG: Rp21.000, potongan ongkir Rp20.000 → dibayar Rp1.000, estimasi 2-3 hari', $hasil);
        $this->assertStringContainsString('jangan pernah "gratis ongkir"', $hasil);

        $salah = app(\App\Modules\CRM\Agents\AgenTools::class)->jalankan('cek_ongkir',
            ['tujuan' => 'Tembalang', 'barang' => [['sku' => 'TIDAK-ADA', 'jumlah' => 1]]], null, CrmAgent::kode(CrmAgent::CS_WHATSAPP))['content'];
        $this->assertStringContainsString('SKU tidak dikenal: TIDAK-ADA', $salah);
        $this->assertSame($p->id, $p->fresh()->id);
    }

    public function test_cek_ongkir_alamat_beberapa_kata_dicari_per_kata_dan_satu_kata_ditolak(): void
    {
        \App\Core\Inventory\Product::create(['sku' => 'KS-3K', 'name' => 'Kotak Saran 3 Kotak', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true, 'weight_gram' => 800]);
        $penyedia = \Mockery::mock(\App\Modules\Shipping\Contracts\ShippingProvider::class);
        // Seperti Jubelio: kueri beberapa kata tidak menemukan apa pun.
        $penyedia->shouldReceive('searchAreas')->andReturnUsing(fn ($q) => ['areas' => match (mb_strtolower($q)) {
            'coblong' => [
                ['id' => 'X9', 'postal_code' => '99999', 'subdistrict' => 'Coblong', 'district' => 'Lain', 'city' => 'Kab. Entah', 'province' => 'Riau'],
                ['id' => 'B2', 'postal_code' => '40132', 'subdistrict' => 'Lebak Gede', 'district' => 'Coblong', 'city' => 'KOTA BANDUNG', 'province' => 'Jawa Barat'],
            ],
            default => [],
        }]);
        $this->mock(\App\Modules\Shipping\ShippingManager::class, function ($m) use ($penyedia) {
            $m->shouldReceive('defaultProviderKey')->andReturn('jubelio_shipment');
            $m->shouldReceive('provider')->andReturn($penyedia);
            $m->shouldReceive('rates')->withArgs(fn ($b) => $b['destination_area_id'] === 'B2')
                ->andReturn(['rates' => [['courier_name' => 'J&T', 'service_name' => 'EZ', 'price' => 25000, 'etd' => '2-3 hari']], 'errors' => []]);
        });
        $alat = app(\App\Modules\CRM\Agents\AgenTools::class);
        $wa = CrmAgent::kode(CrmAgent::CS_WHATSAPP);

        $hasil = $alat->jalankan('cek_ongkir', ['tujuan' => 'kec. Coblong, Bandung', 'barang' => [['sku' => 'KS-3K', 'jumlah' => 1]]], null, $wa)['content'];
        $this->assertStringContainsString('Ongkir ke Lebak Gede, Coblong, KOTA BANDUNG, Jawa Barat 40132', $hasil, 'Yang cocok kecamatan + kota didahulukan.');

        $satu = $alat->jalankan('cek_ongkir', ['tujuan' => 'Semarang', 'barang' => [['sku' => 'KS-3K', 'jumlah' => 1]]], null, $wa)['content'];
        $this->assertStringContainsString('kurang lengkap', $satu);
    }

    public function test_cek_ongkir_instan_dari_shareloc_pembeli(): void
    {
        \App\Core\Inventory\Product::create(['sku' => 'KS-3K', 'name' => 'Kotak Saran 3 Kotak', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true, 'weight_gram' => 800]);
        $c = $this->chat('kak bisa gosend?');
        $alat = app(\App\Modules\CRM\Agents\AgenTools::class);
        $wa = CrmAgent::kode(CrmAgent::CS_WHATSAPP);
        $input = ['instan' => true, 'barang' => [['sku' => 'KS-3K', 'jumlah' => 1]]];

        $this->assertStringContainsString('Belum ada shareloc', $alat->jalankan('cek_ongkir', $input, $c, $wa)['content']);

        CrmMessage::create(['conversation_id' => $c->id, 'direction' => CrmMessage::MASUK, 'message_type' => 'location',
            'content' => null, 'sent_at' => now()->subMinute(), 'raw' => ['location' => ['latitude' => -7.05, 'longitude' => 110.44, 'name' => 'Rumah']]]);
        $this->mock(\App\Modules\Shipping\Services\PointAddressResolver::class, fn ($m) => $m->shouldReceive('resolve')
            ->withArgs(fn ($lat, $lng) => $lat == -7.05 && $lng == 110.44)
            ->andReturn(['address' => 'Jl. Banjarsari, Tembalang', 'area_id' => 'A1', 'area_label' => 'Tembalang', 'postal_code' => '50275', 'error' => null]));
        $this->mock(\App\Modules\Shipping\ShippingManager::class, function ($m) {
            $m->shouldReceive('defaultProviderKey')->andReturn('jubelio_shipment');
            $m->shouldReceive('provider')->andReturn(\Mockery::mock(\App\Modules\Shipping\Contracts\ShippingProvider::class));
            $m->shouldReceive('rates')->withArgs(fn ($b) => $b['mode'] === 'instant' && $b['destination_latitude'] == -7.05
                && $b['destination_postal_code'] === '50275')
                ->andReturn(['rates' => [['courier_name' => 'GoSend', 'service_name' => 'Instant', 'price' => 25000, 'etd' => '1-2 jam']], 'errors' => []]);
        });

        $hasil = $alat->jalankan('cek_ongkir', $input, $c, $wa)['content'];
        $this->assertStringContainsString('kurir instan ke shareloc pembeli (Jl. Banjarsari, Tembalang)', $hasil);
        $this->assertStringContainsString('GoSend Instant: Rp25.000', $hasil);
    }

    public function test_info_pesanan_wa_tanpa_pelanggan_minta_nomor_nota(): void
    {
        $this->chat('kak pesanan saya gimana?');
        $this->claude->antrekan(FakeClaudeClient::panggilAlat('info_pesanan', []))
            ->antrekan($this->usul('A4', 'boleh minta nomor notanya kak?'));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $this->assertStringContainsString('belum terhubung', CrmSaranAi::firstOrFail()->run->jejak[0]['hasil']);
    }

    public function test_koreksi_owner_hanya_dipakai_agen_kanalnya(): void
    {
        $wa = $this->chat();
        $shopee = CrmConversation::findOrCreateFor('777', CrmConversation::KANAL_SHOPEE, '101');
        foreach ([[$wa, 'koreksi untuk WA'], [$shopee, 'koreksi untuk Shopee']] as [$c, $koreksi]) {
            $m = $this->pesan($c, CrmMessage::MASUK, 'tanya ' . $c->channel, now()->subHour());
            CrmSaranAi::create(['conversation_id' => $c->id, 'untuk_message_id' => $m->id, 'teks' => 'salah', 'status' => CrmSaranAi::DIABAIKAN,
                'tinjauan' => CrmSaranAi::SALAH, 'koreksi' => $koreksi, 'ditinjau_at' => now()]);
        }

        $sysWa = AgenPrompt::system(CrmAgent::kode(CrmAgent::CS_WHATSAPP), null);
        $sysShopee = AgenPrompt::system(CrmAgent::kode(CrmAgent::CS_SHOPEE), null);

        $this->assertStringContainsString('koreksi untuk WA', $sysWa);
        $this->assertStringNotContainsString('koreksi untuk Shopee', $sysWa);
        $this->assertStringContainsString('koreksi untuk Shopee', $sysShopee);
        $this->assertStringNotContainsString('koreksi untuk WA', $sysShopee);
    }
}
