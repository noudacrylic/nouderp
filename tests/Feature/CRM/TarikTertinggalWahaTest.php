<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\User;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tombol "Tarik pesan tertinggal" (Pengaturan → WAHA): mencatat permintaan, lalu
 * crm:tarik-tertinggal-waha menarik riwayat nomor utama ke Inbox (kejadian 6 Nov 2026).
 */
class TarikTertinggalWahaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        Http::preventStrayRequests();

        CrmSetting::for('waha')->forceFill([
            'is_enabled' => true,
            'api_key'    => 'kunci-waha',
            'base_url'   => 'http://127.0.0.1:3000',
            'config'     => ['sesi' => [
                'utama'      => ['session' => 'utama', 'last_status' => 'WORKING', 'pernah_tertaut' => true],
                'notifikasi' => ['session' => 'notifikasi'],
            ]],
        ])->save();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_tombol_hanya_mencatat_permintaan(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post(route('settings.waha.tarik', ['peran' => 'utama']))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertNotEmpty(CrmSetting::for('waha')->sesi('utama')['tarik_diminta_at'] ?? null);
        Http::assertNothingSent();
    }

    public function test_tombol_ditolak_bila_sesi_belum_working(): void
    {
        $w = CrmSetting::for('waha');
        $w->simpanSesi('utama', ['last_status' => 'FAILED']);

        $this->actingAs($this->admin())->post(route('settings.waha.tarik', ['peran' => 'utama']))
            ->assertSessionHas('error');

        $this->assertEmpty(CrmSetting::for('waha')->sesi('utama')['tarik_diminta_at'] ?? null);
    }

    public function test_perintah_menarik_pesan_lalu_mencatat_hasil(): void
    {
        $chat = '628123456789@s.whatsapp.net';
        Http::fake(array_reverse([
            'http://127.0.0.1:3000/api/sessions/utama' => Http::response(['name' => 'utama', 'status' => 'WORKING']),
            'http://127.0.0.1:3000/api/utama/chats*'   => Http::response([['id' => $chat, 'conversationTimestamp' => now()->getTimestamp()]]),
            'http://127.0.0.1:3000/api/utama/chats/' . rawurlencode($chat) . '/messages*' => Http::response([
                ['id' => 'false_' . $chat . '_A1', 'timestamp' => now()->subMinutes(30)->getTimestamp(), 'fromMe' => false, 'body' => 'Halo kak, pesanan saya?', 'hasMedia' => false],
            ]),
        ], true));

        // Tanpa permintaan: diam.
        $this->artisan('crm:tarik-tertinggal-waha')->assertSuccessful();
        $this->assertSame(0, CrmMessage::count());
        Http::assertNothingSent();

        CrmSetting::for('waha')->simpanSesi('utama', ['tarik_diminta_at' => now()->toIso8601String()]);
        $this->artisan('crm:tarik-tertinggal-waha')->assertSuccessful();

        $this->assertSame(1, CrmMessage::count());
        $sesi = CrmSetting::for('waha')->sesi('utama');
        $this->assertNull($sesi['tarik_diminta_at']);
        $this->assertNotEmpty($sesi['tarik_selesai_at']);
        $this->assertStringContainsString('1 pesan baru', $sesi['tarik_hasil']);
        $this->assertSame(now()->subDay()->toDateString(), $sesi['tarik_sejak'], 'jendela minimal sejak kemarin');

        // Diulang: tidak menggandakan.
        CrmSetting::for('waha')->simpanSesi('utama', ['tarik_diminta_at' => now()->toIso8601String()]);
        $this->artisan('crm:tarik-tertinggal-waha')->assertSuccessful();
        $this->assertSame(1, CrmMessage::count());
    }

    public function test_layar_menampilkan_tombol_dan_hasil_terakhir(): void
    {
        CrmSetting::for('waha')->simpanSesi('utama', [
            'tarik_mulai_at' => now()->subMinutes(3)->toIso8601String(), 'tarik_selesai_at' => now()->subMinutes(2)->toIso8601String(),
            'tarik_hasil' => 'Pesan direkam: 106 baru, 500 dilewati', 'tarik_sejak' => '2026-11-05',
        ]);

        $this->actingAs($this->admin())->get(route('settings.waha.edit'))
            ->assertOk()->assertSee('Tarik pesan tertinggal')->assertSee('106 baru');
    }
}
