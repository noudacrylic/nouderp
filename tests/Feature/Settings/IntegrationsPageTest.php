<?php

namespace Tests\Feature\Settings;

use App\Models\PaymentSetting;
use App\Models\R2Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman hub Integrasi.
 *
 * Yang dijaga di sini satu hal saja, tapi mahal: **satu kredensial terenkripsi
 * yang tak bisa didekripsi tidak boleh mematikan seluruh halaman.** Itu terjadi
 * setiap kali dump database server dipakai di lokal (APP_KEY berbeda), dan
 * akibatnya jauh melebihi sebabnya — kartu Midtrans, Jubelio, RajaOngkir, CRM,
 * semuanya ikut hilang karena satu baris config QRIS tak terbaca.
 */
class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_halaman_tetap_terbuka_meski_kredensial_terenkripsi_tak_terbaca(): void
    {
        $payment = PaymentSetting::singleton();
        $r2      = R2Setting::query()->firstOrCreate([], ['is_active' => true]);

        // Tulis langsung lewat query builder supaya melewati cast: inilah bentuk
        // data yang tertinggal saat APP_KEY berganti — bukan null, tapi cipher
        // yang tak bisa dibuka lagi.
        DB::table('payment_settings')->where('id', $payment->id)
            ->update(['config' => 'eyJpdiI6InNhbXBhaCIsInZhbHVlIjoic2FtcGFoIn0=']);
        DB::table('r2_settings')->where('id', $r2->id)
            ->update(['secret_access_key' => 'eyJpdiI6InNhbXBhaCIsInZhbHVlIjoic2FtcGFoIn0=']);

        $this->actingAs($this->admin())
            ->get(route('settings.integrations.index'))
            ->assertOk()
            ->assertSee('CRM WhatsApp');

        // Yang tak terbaca dianggap kosong, bukan melempar exception.
        $this->assertNull(PaymentSetting::singleton()->conf('qris_api_key'));
        $this->assertFalse(R2Setting::query()->first()->isConfigured());
    }

    public function test_kartu_crm_menunjuk_ke_layar_pengaturan_crm(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.integrations.index'))
            ->assertOk()
            ->assertSee(route('settings.crm.edit'), false);
    }
}
