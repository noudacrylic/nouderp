<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
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
        $this->assertSame(['cek_stok', 'info_pesanan', 'deskripsi_produk', 'usulkan_balasan'], array_column($muatan['tools'], 'name'));
        $this->assertStringContainsString('Bukan pelanggan', $muatan['system'][0]['text'], 'Pengetahuan = daftar tanya-jawab WA.');
        $this->assertStringContainsString('chat WHATSAPP', $muatan['system'][0]['text']);
        $this->assertStringNotContainsString('DRAF', $muatan['system'][0]['text']);
        $this->assertStringContainsString('Z2', json_encode($muatan['tools'][3], JSON_UNESCAPED_UNICODE), 'Kode butir WA, bukan I1/I2 Shopee.');

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

    public function test_lemparan_wa_tidak_mengirim_dan_tidak_mengoper_chat(): void
    {
        $this->travelTo(now()->next('Monday')->setTime(22, 0));
        $pemegang = User::factory()->create();
        $c = $this->chat('kak bisa custom kotak 20x30?');
        $c->forceFill(['owner_user_id' => $pemegang->id])->save();
        CrmAgent::where('kode', CrmAgent::CS_WHATSAPP)->update(['kirim_otomatis' => CrmAgent::KIRIM_PENUH]);
        $this->claude->antrekan($this->usul('D1', 'bisa kak, tebalnya mau 2 mm atau 3 mm?', 'owner'));

        $this->artisan('crm:saran-ai')->assertSuccessful();

        $s = CrmSaranAi::firstOrFail();
        $this->assertSame(['owner', null], [$s->lempar_ke, $s->otomatis]);
        $this->assertSame($pemegang->id, (int) $c->refresh()->owner_user_id, 'Mode saran WA: chat tidak dioper.');
        $this->assertSame(1, CrmMessage::where('direction', CrmMessage::KELUAR)->count(), 'Agen WA tak punya mode kirim di layar; tetap tidak mengirim.');
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
