<?php

namespace Tests\Feature\CRM;

use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\NamaKontakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nama kontak untuk lead yang belum beli.
 *
 * Webhook vendor tidak membawa nama (diverifikasi atas 105 kiriman sungguhan),
 * jadi nama profil WhatsApp ditanyakan terpisah. Yang dijaga: nama ketikan CS
 * tidak pernah ditimpa otomatis, dan nama profil tidak pernah bocor ke sapaan
 * pesan untuk pelanggan.
 */
class NamaKontakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);   // provider() = driver palsu
        app(ChatManager::class)->fake()->reset();
    }

    private function chat(array $atribut = []): CrmConversation
    {
        return CrmConversation::create($atribut + [
            'channel'      => 'whatsapp',
            'contact_key'  => '6285867614623',
            'status'       => CrmConversation::STATUS_AKTIF,
            'unread_count' => 0,
        ]);
    }

    private function profil(string $nomor, string $nama): void
    {
        app(ChatManager::class)->fake()->profil[$nomor] = $nama;
    }

    public function test_penjadwal_mengisi_nama_profil_whatsapp(): void
    {
        $chat = $this->chat();
        $this->profil('6285867614623', 'Ferina mei');

        $this->artisan('crm:ambil-nama-kontak')->assertSuccessful();

        $chat->refresh();
        $this->assertSame('Ferina mei', $chat->display_name);
        $this->assertSame(CrmConversation::NAMA_WHATSAPP, $chat->name_source);
        $this->assertSame('Ferina mei', $chat->namaTampil());
    }

    /** Kontak tak bernama ditanya sekali, bukan tiap menit selamanya. */
    public function test_kontak_tak_bernama_tidak_ditanya_ulang_tanpa_pesan_baru(): void
    {
        $chat = $this->chat(['last_inbound_at' => now()->subMinute()]);

        app(NamaKontakService::class)->lengkapiYangKosong();
        $this->assertNotNull($chat->fresh()->name_checked_at);

        // Vendor kini tahu namanya, tapi belum ada pesan masuk baru → tidak ditanya.
        $this->profil('6285867614623', 'Ferina mei');
        $this->assertSame(0, app(NamaKontakService::class)->lengkapiYangKosong()['dicoba']);

        // Pelanggan membalas → kesempatan bertanya terbuka lagi.
        $chat->forceFill(['last_inbound_at' => now()->addMinute()])->save();
        $hasil = app(NamaKontakService::class)->lengkapiYangKosong();

        $this->assertSame(1, $hasil['dapat']);
        $this->assertSame('Ferina mei', $chat->fresh()->display_name);
    }

    public function test_nama_ketikan_cs_tidak_ditimpa_penjadwal(): void
    {
        $chat = $this->chat();
        $this->profil('6285867614623', 'Ferina mei');

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->post(route('crm.inbox.nama', $chat), ['display_name' => 'Bu Ferina — kotak kepuasan'])
            ->assertRedirect();

        app(NamaKontakService::class)->ambilDariWhatsapp($chat->fresh());

        $chat->refresh();
        $this->assertSame('Bu Ferina — kotak kepuasan', $chat->display_name);
        $this->assertSame(CrmConversation::NAMA_MANUAL, $chat->name_source);
    }

    public function test_tombol_ambil_dari_whatsapp_menimpa_nama_ketikan(): void
    {
        $chat = $this->chat(['display_name' => 'Salah ketik', 'name_source' => CrmConversation::NAMA_MANUAL]);
        $this->profil('6285867614623', 'Ferina mei');

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->post(route('crm.inbox.nama', $chat), ['ambil_wa' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Ferina mei', $chat->fresh()->display_name);
        $this->assertSame(CrmConversation::NAMA_WHATSAPP, $chat->fresh()->name_source);
    }

    public function test_mengosongkan_nama_kembali_ke_nama_whatsapp(): void
    {
        $chat = $this->chat(['display_name' => 'Bu Ferina', 'name_source' => CrmConversation::NAMA_MANUAL]);
        $this->profil('6285867614623', 'Ferina mei');

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->post(route('crm.inbox.nama', $chat), ['display_name' => ''])
            ->assertRedirect();

        $this->assertSame('Ferina mei', $chat->fresh()->display_name);
        $this->assertSame(CrmConversation::NAMA_WHATSAPP, $chat->fresh()->name_source);
    }

    /** Nama profil bisa berupa apa saja — tak boleh jadi "Halo Kak Toko Berkah Jaya". */
    public function test_nama_profil_tidak_dipakai_untuk_sapaan_pesan(): void
    {
        $chat = $this->chat(['display_name' => 'Toko Berkah Jaya', 'name_source' => CrmConversation::NAMA_WHATSAPP]);

        $this->assertSame('Kak', $chat->sapaan());
        $this->assertSame('Toko Berkah Jaya', $chat->namaTampil());

        $chat->forceFill(['display_name' => 'Ferina', 'name_source' => CrmConversation::NAMA_MANUAL])->save();
        $this->assertSame('Kak Ferina', $chat->fresh()->sapaan());
    }

    public function test_nama_pelanggan_erp_tetap_di_atas_nama_kontak(): void
    {
        $pelanggan = Customer::create(['code' => 'CUST-NK', 'name' => 'CV Maju', 'is_active' => true]);
        $chat = $this->chat([
            'customer_id'  => $pelanggan->id,
            'display_name' => 'Ferina', 'name_source' => CrmConversation::NAMA_MANUAL,
        ]);

        $this->assertSame('CV Maju', $chat->namaTampil());
        $this->assertSame('Kak CV Maju', $chat->sapaan());
    }

    public function test_gagal_vendor_tidak_merusak_nama_yang_ada(): void
    {
        $chat = $this->chat(['display_name' => 'Ferina mei', 'name_source' => CrmConversation::NAMA_WHATSAPP]);
        app(ChatManager::class)->fake()->profilError = 'HTTP 500';

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->post(route('crm.inbox.nama', $chat), ['ambil_wa' => 1])
            ->assertSessionHas('error');

        $this->assertSame('Ferina mei', $chat->fresh()->display_name);
    }
}
