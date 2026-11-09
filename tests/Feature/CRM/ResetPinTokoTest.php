<?php

namespace Tests\Feature\CRM;

use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pelanggan lupa PIN toko online (9 Nov 2026): admin mengosongkan PIN dari chat WA bernomor
 * sama; checkout berikutnya menyimpan PIN baru.
 */
class ResetPinTokoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function pelanggan(string $hp): Customer
    {
        return Customer::create(['code' => 'WEB-' . $hp, 'name' => 'Yuni', 'phone' => $hp,
            'web_order_pin' => Hash::make('1234'), 'is_active' => true]);
    }

    public function test_reset_pin_dari_chat_bernomor_sama(): void
    {
        $c = $this->pelanggan('+62 812-5252-7212');
        $lain = $this->pelanggan('081299998888');
        $chat = CrmConversation::findOrCreateFor('6281252527212');

        $this->actingAs($this->admin)->get(route('crm.inbox.show', $chat))->assertOk()->assertSee('Reset PIN toko online');
        $this->actingAs($this->admin)->post(route('crm.inbox.reset-pin-toko', $chat))->assertSessionHas('success');

        $this->assertNull($c->fresh()->web_order_pin);
        $this->assertNotNull($lain->fresh()->web_order_pin, 'akun nomor lain tidak tersentuh');

        // Sudah kosong → tombol hilang, panggilan ulang ditolak halus.
        $this->actingAs($this->admin)->get(route('crm.inbox.show', $chat))->assertOk()->assertDontSee('Reset PIN toko online');
        $this->actingAs($this->admin)->post(route('crm.inbox.reset-pin-toko', $chat))->assertSessionHas('error');
    }

    public function test_status_pin_toko_mengenali_nomor_beda_format(): void
    {
        Cache::flush();
        $key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $key]);
        $this->pelanggan('+62 812-5252-7212');

        $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'])
            ->postJson(route('api.storefront.orders.pin-status'), ['phone' => '081252527212'])
            ->assertOk()->assertJsonPath('data.registered', true);
    }
}
