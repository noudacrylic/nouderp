<?php

namespace Tests\Feature\CRM;

use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chat yang sudah terlanjur Lead harus menemukan pelanggannya belakangan.
 *
 * Pencocokan nomor dulu hanya berjalan saat pesan masuk. Pelanggan yang
 * nomornya disimpan sesudah orangnya menyapa tetap terbaca Lead tanpa riwayat
 * pesanan — dan Buat SO dari situ melahirkan pelanggan kembar.
 */
class TautUlangPelangganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        config(['crm.dry_run' => true]);
    }

    private function pelanggan(array $atribut = []): Customer
    {
        return Customer::create(array_merge([
            'code'      => 'CUST-' . uniqid(),
            'name'      => 'Bu Sari',
            'is_active' => true,
        ], $atribut));
    }

    public function test_menyimpan_pelanggan_menautkan_chat_lead_bernomor_sama(): void
    {
        $chat = CrmConversation::findOrCreateFor('6282136990730');
        $this->assertNull($chat->customer_id);

        $c = $this->pelanggan(['phone' => '0821-3699-0730']);

        $this->assertSame($c->id, $chat->fresh()->customer_id);
    }

    public function test_membetulkan_nomor_pelanggan_menautkan_chat(): void
    {
        $chat = CrmConversation::findOrCreateFor('6282136990730');
        $c    = $this->pelanggan(['phone' => '0811111111']);
        $this->assertNull($chat->fresh()->customer_id);

        $c->update(['phone' => '082136990730']);

        $this->assertSame($c->id, $chat->fresh()->customer_id);
    }

    public function test_tautan_yang_sudah_ada_tidak_digeser(): void
    {
        $lama = $this->pelanggan(['name' => 'Pilihan Admin']);
        $chat = CrmConversation::findOrCreateFor('6282136990730');
        $chat->forceFill(['customer_id' => $lama->id])->save();

        $this->pelanggan(['phone' => '082136990730']);

        $this->assertSame($lama->id, $chat->fresh()->customer_id);
    }

    public function test_membuka_thread_menautkan_lewat_nomor_cabang(): void
    {
        $chat = CrmConversation::findOrCreateFor('6282136990730');
        $c    = $this->pelanggan(['phone' => '0811111111']);
        $c->branches()->create(['name' => 'Cabang Solo', 'phone' => '082136990730']);

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('crm.inbox.show', $chat))->assertOk();

        $this->assertSame($c->id, $chat->fresh()->customer_id);
    }

    public function test_perintah_tautkan_membereskan_data_lama(): void
    {
        $chat = CrmConversation::findOrCreateFor('6282136990730');
        $c    = $this->pelanggan(['phone' => '0811111111']);
        // Tulis langsung tanpa observer, meniru data sebelum perbaikan.
        Customer::withoutEvents(fn () => $c->update(['phone' => '082136990730']));
        $this->assertNull($chat->fresh()->customer_id);

        $this->artisan('crm:tautkan-pelanggan')->assertSuccessful();

        $this->assertSame($c->id, $chat->fresh()->customer_id);
    }

    public function test_nol_lokal_di_belakang_kode_negara_dinormalkan(): void
    {
        $this->assertSame('6282136990730', PhoneNumber::normalize('+62 (0)821-3699-0730'));
        $this->assertSame('6282136990730', PhoneNumber::normalize('62082136990730'));
    }
}
