<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Models\User;
use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Assistant\Services\FakeClaudeClient;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Karyawan virtual CS Shopee, mode saran: saran dibuat untuk chat Shopee yang belum dibalas,
 * tidak mengirim apa pun, dan Pakai / Abaikan admin tercatat untuk evaluasi.
 */
class SaranAiTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->claude = new FakeClaudeClient();
        $this->app->instance(ClaudeClient::class, $this->claude);
        config(['crm.agen.saran_aktif' => true, 'crm.dry_run' => true]);
    }

    private function chat(string $isi = 'kak buka jam berapa?'): CrmConversation
    {
        $c = CrmConversation::findOrCreateFor('777', CrmConversation::KANAL_SHOPEE, '101');
        $this->pesan($c, CrmMessage::MASUK, $isi, now()->subMinutes(2));
        // Balasan otomatis Shopee sesudahnya bukan jawaban manusia.
        $this->pesan($c, CrmMessage::KELUAR, 'Selamat datang di Noud Acrylic Shop…', now()->subMinutes(2)->addSecond(), CrmMessage::SOURCE_SHOPEE_OTOMATIS);
        $c->forceFill(['last_inbound_at' => now()->subMinutes(2), 'last_outbound_at' => null])->save();

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

    public function test_saran_dibuat_sekali_untuk_chat_shopee_yang_belum_dibalas(): void
    {
        $c = $this->chat();
        $this->claude->antrekan($this->usul('A7', 'Kami buka Senin–Sabtu jam 08.00–16.00 kak 🙏'));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $s = CrmSaranAi::firstOrFail();
        $this->assertSame([$c->id, 'A7', CrmSaranAi::BARU, null], [$s->conversation_id, $s->butir, $s->status, $s->lempar_ke]);
        $this->assertSame(CrmAgentRun::MODE_SARAN, $s->run->mode);
        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->whereNull('source')->count(), 'Saran tidak mengirim apa pun.');

        $muatan = $this->claude->diterima[0];
        $this->assertCount(1, $this->claude->diterima, 'Alat usulkan_balasan menutup giliran — tanpa putaran tambahan.');
        $this->assertSame('claude-haiku-5-5', $muatan['model']);
        $this->assertSame(['effort' => 'low'], $muatan['output_config']);
        $this->assertSame(['cek_stok', 'info_pesanan', 'usulkan_balasan'], array_column($muatan['tools'], 'name'));
        $this->assertStringContainsString('Jl. Suren Raya No.25A', $muatan['system'][0]['text'], 'Pengetahuan = daftar tanya-jawab.');

        $this->artisan('crm:saran-ai')->assertSuccessful();
        $this->assertSame(1, CrmSaranAi::count(), 'Satu saran per pesan.');
        $this->assertCount(1, $this->claude->diterima);
    }

    public function test_tanpa_saklar_atau_sudah_dibalas_tidak_membuat_saran(): void
    {
        $c = $this->chat();
        config(['crm.agen.saran_aktif' => false]);
        $this->artisan('crm:saran-ai')->assertSuccessful();
        $this->assertSame(0, CrmSaranAi::count());

        config(['crm.agen.saran_aktif' => true]);
        $c->forceFill(['last_outbound_at' => now()->subMinute()])->save();
        $this->artisan('crm:saran-ai')->assertSuccessful();
        $this->assertSame(0, CrmSaranAi::count(), 'Sudah dibalas manusia.');
        $this->assertCount(0, $this->claude->diterima);
    }

    public function test_info_pesanan_membaca_pesanan_pembeli_dan_produk_desain(): void
    {
        $c = $this->chat('kak kapan dikirim?');
        $p = Product::create(['sku' => 'KS-K1-M1', 'name' => 'Kotak Saran', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
        $o = DB::table('shopee_orders')->insertGetId(['env' => 'live', 'shop_id' => 101, 'order_sn' => '261031ABCDEFGH', 'buyer_user_id' => 777,
            'status' => 'READY_TO_SHIP', 'dibuat_shopee_at' => now()->subHour(), 'raw' => json_encode(['pay_time' => now()->subHour()->timestamp]),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('shopee_order_items')->insert(['shopee_order_id' => $o, 'item_id' => 1, 'sku' => 'KS-K1-M1', 'nama' => 'Kotak Saran', 'qty' => 1,
            'product_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->claude->antrekan(FakeClaudeClient::panggilAlat('info_pesanan', []))
            ->antrekan($this->usul('A1', 'Pesanan kakak dikirim setelah desainnya disetujui ya kak.'));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $hasil = CrmSaranAi::firstOrFail()->run->jejak[0]['hasil'];
        $this->assertStringContainsString('261031ABCDEFGH', $hasil);
        $this->assertStringContainsString('butuh desain', $hasil);
        $this->assertStringContainsString('ready', $hasil);
    }

    public function test_pesan_tak_perlu_dibalas_tidak_ditampilkan(): void
    {
        $c = $this->chat('oke kak makasih');
        $this->claude->antrekan($this->usul('I', ''));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $this->assertSame(CrmSaranAi::TAK_PERLU, CrmSaranAi::firstOrFail()->status);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->getJson(route('crm.inbox.saran-ai', $c->id))->assertOk()->assertJson(['saran' => null]);
        $c->forceFill(['owner_user_id' => $admin->id])->save();   // kotak ketik hanya untuk chat berpemegang
        $this->actingAs($admin)->get(route('crm.inbox.show', $c->id))->assertOk()->assertSee('saranAiCrm', false)->assertSee('name="saran_ai"', false);
    }

    public function test_pakai_utuh_diubah_dan_abaikan_tercatat(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $c = $this->chat();
        $c->forceFill(['owner_user_id' => $admin->id])->save();
        $buat = fn () => CrmSaranAi::create(['conversation_id' => $c->id, 'butir' => 'A7', 'teks' => 'Kami buka jam 8 kak', 'status' => CrmSaranAi::BARU]);

        $s = $buat();
        $this->actingAs($admin)->getJson(route('crm.inbox.saran-ai', $c->id))->assertOk()->assertJsonPath('saran.id', $s->id);
        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $c->id), ['teks' => 'Kami buka jam 8 kak', 'saran_ai' => $s->id])->assertOk();
        $s->refresh();
        $this->assertSame([CrmSaranAi::DIPAKAI, false, $admin->id], [$s->status, $s->diubah, $s->diputus_oleh]);

        $s = $buat();
        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $c->id), ['teks' => 'Kami buka jam 8 pagi kak', 'saran_ai' => $s->id])->assertOk();
        $this->assertTrue($s->fresh()->diubah, 'Dipakai tapi disunting.');

        $s = $buat();
        $this->actingAs($admin)->postJson(route('crm.inbox.balas', $c->id), ['teks' => 'Buka jam 8 ya'])->assertOk();
        $s->refresh();
        $this->assertSame([CrmSaranAi::DIABAIKAN, 'Buka jam 8 ya'], [$s->status, $s->teks_terkirim], 'Balasan admin sendiri = bahan evaluasi.');

        $s = $buat();
        $this->actingAs($admin)->postJson(route('crm.inbox.saran-ai.abaikan', [$c->id, $s->id]), ['catatan' => 'salah butir'])->assertOk();
        $this->assertSame([CrmSaranAi::DIABAIKAN, 'salah butir'], [$s->fresh()->status, $s->fresh()->catatan]);
    }
    public function test_pengetahuan_dimuat_ulang_dari_berkas_hanya_bila_berubah(): void
    {
        $agen = \App\Modules\CRM\Models\CrmAgent::kode('cs-shopee');
        $this->assertStringContainsString('BC-4x4', $agen->pengetahuanAktif()->isi, 'Tabel ukuran stiker BC ikut.');

        $agen->pengetahuanAktif()->update(['isi' => 'lama']);
        $this->artisan('crm:muat-pengetahuan')->assertSuccessful();
        $this->assertSame(2, $agen->pengetahuanAktif()->versi);
        $this->artisan('crm:muat-pengetahuan')->assertSuccessful();
        $this->assertSame(2, $agen->fresh()->pengetahuanAktif()->versi, 'Isi sama — tidak ada versi baru.');
    }
    public function test_cek_stok_memberi_link_shopee_tanpa_harga(): void
    {
        $this->chat('kotak saran 3 kotak tanpa koin ada?');
        $cari = \Mockery::mock(\App\Modules\CRM\Services\PencarianProdukService::class);
        $cari->shouldReceive('untukAgen')->andReturn([['nama' => 'Kotak Saran 3 Kotak Tanpa Koin', 'sku' => 'KS-K3-M2', 'stok' => 7.0,
            'harga' => 99000, 'harga_coret' => null, 'promo' => null, 'preorder' => false, 'custom' => false, 'url' => 'https://noudacrylic.com/x']]);
        $this->app->instance(\App\Modules\CRM\Services\PencarianProdukService::class, $cari);
        DB::table('shopee_items')->insert(['env' => app(\App\Modules\Marketplace\Shopee\Services\ShopeeClient::class)->env(), 'shop_id' => 101,
            'item_id' => 555, 'model_id' => 1, 'model_sku' => 'KS-K3-M2', 'nama' => 'Kotak Saran', 'status' => 'NORMAL', 'created_at' => now(), 'updated_at' => now()]);

        $this->claude->antrekan(FakeClaudeClient::panggilAlat('cek_stok', ['kata_kunci' => 'kotak saran 3 kotak tanpa koin']))
            ->antrekan($this->usul('B1', 'Ready kak'));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $hasil = CrmSaranAi::firstOrFail()->run->jejak[0]['hasil'];
        $this->assertStringContainsString('https://shopee.co.id/product/101/555', $hasil);
        $this->assertStringContainsString('stok 7', $hasil);
        $this->assertStringNotContainsString('99', $hasil, 'Harga tidak disebut.');
        $this->assertStringNotContainsString('noudacrylic.com', $hasil, 'Tautan luar Shopee tidak dibawa.');
    }
    public function test_fokus_produk_dari_kartu_produk_pembeli(): void
    {
        $c = $this->chat('ukuran stikernya berapa kak?');
        CrmMessage::create(['conversation_id' => $c->id, 'direction' => CrmMessage::MASUK, 'message_type' => 'text',
            'content' => '🛍️ Produk: Kotak Saran 3 Kotak', 'sent_at' => now()->subMinutes(3), 'raw' => ['message_type' => 'item', 'content' => ['item_id' => 555]]]);
        foreach ([[1, 'Tanpa Koin', 'KS-K3-M2'], [2, 'Puas, Tidak Puas', 'KS-K3-M1']] as [$m, $nama, $sku]) {
            DB::table('shopee_items')->insert(['env' => 'live', 'shop_id' => 101, 'item_id' => 555, 'model_id' => $m, 'model_sku' => $sku,
                'nama' => 'Kotak Saran 3 Kotak', 'nama_model' => $nama, 'stok' => 4, 'status' => 'NORMAL', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->claude->antrekan($this->usul('C4', 'Untuk kotak saran ini …'));
        $this->artisan('crm:saran-ai')->assertSuccessful();

        $pesan = json_encode($this->claude->diterima[0]['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Produk yang sedang dibahas', $pesan);
        $this->assertStringContainsString('https://shopee.co.id/product/101/555', $pesan);
        $this->assertStringContainsString('Tanpa Koin (SKU KS-K3-M2', $pesan);
    }
}
