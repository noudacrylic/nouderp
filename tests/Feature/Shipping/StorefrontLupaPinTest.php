<?php

namespace Tests\Feature\Shipping;

use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Modules\CRM\Providers\FakeNotificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Lupa PIN?" mandiri di toko online (9 Nov 2026): kode 6 angka lewat WA notifikasi →
 * kode + PIN baru. Yang dijaga: kode benar-benar dikirim ke nomor akun, PIN hanya berganti
 * dengan kode yang benar, kode hangus setelah 5 kali salah, permintaan kode dibatasi.
 */
class StorefrontLupaPinTest extends TestCase
{
    use RefreshDatabase;

    private string $key;
    private FakeNotificationProvider $wa;
    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);
        $this->wa = new FakeNotificationProvider();
        $this->app->instance('storefront.pin.pengirim', $this->wa);
        $this->c = Customer::create(['code' => 'WEB-1', 'name' => 'Yuni', 'phone' => '+62 812-5252-7212',
            'web_order_pin' => Hash::make('1234'), 'is_active' => true]);
    }

    private function api(string $rute, array $isi)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'])
            ->postJson(route($rute), $isi);
    }

    private function kodeTerkirim(): string
    {
        return end($this->wa->terkirim)['body'][0];
    }

    public function test_kode_dikirim_lalu_pin_baru_tersimpan(): void
    {
        $this->api('api.storefront.orders.pin-reset.minta', ['phone' => '081252527212'])->assertOk();

        $kirim = end($this->wa->terkirim);
        $this->assertSame('6281252527212', $kirim['to']);
        $this->assertSame('kode_reset_pin', $kirim['template']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $kirim['body'][0]);
        $this->assertStringContainsString($kirim['body'][0], $kirim['teks']);

        $this->api('api.storefront.orders.pin-reset.simpan', ['phone' => '081252527212', 'kode' => '000000', 'pin' => '5678'])
            ->assertStatus(422);
        $this->assertTrue(Hash::check('1234', $this->c->fresh()->web_order_pin), 'kode salah tak mengubah PIN');

        $this->api('api.storefront.orders.pin-reset.simpan', ['phone' => '081252527212', 'kode' => $this->kodeTerkirim(), 'pin' => '5678'])
            ->assertOk();
        $this->assertTrue(Hash::check('5678', $this->c->fresh()->web_order_pin));

        // Kode sekali pakai.
        $this->api('api.storefront.orders.pin-reset.simpan', ['phone' => '081252527212', 'kode' => $this->kodeTerkirim(), 'pin' => '9999'])
            ->assertStatus(422);
    }

    public function test_kode_hangus_setelah_lima_kali_salah(): void
    {
        $this->api('api.storefront.orders.pin-reset.minta', ['phone' => '081252527212'])->assertOk();
        $kode = $this->kodeTerkirim();
        $salah = $kode === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->api('api.storefront.orders.pin-reset.simpan', ['phone' => '081252527212', 'kode' => $salah, 'pin' => '5678'])->assertStatus(422);
        }
        $this->api('api.storefront.orders.pin-reset.simpan', ['phone' => '081252527212', 'kode' => $kode, 'pin' => '5678'])
            ->assertStatus(422)->assertJsonPath('message', 'Kode sudah kedaluwarsa. Minta kode baru.');
        $this->assertTrue(Hash::check('1234', $this->c->fresh()->web_order_pin));
    }

    public function test_nomor_tanpa_pin_dan_batas_permintaan_dan_wa_gagal(): void
    {
        $this->api('api.storefront.orders.pin-reset.minta', ['phone' => '081299998888'])->assertNotFound();
        $this->assertSame([], $this->wa->terkirim);

        // Batas per nomor (rem per IP 5/menit ada di route; di sini 3 per nomor per jam).
        for ($i = 0; $i < 3; $i++) {
            $this->api('api.storefront.orders.pin-reset.minta', ['phone' => '081252527212'])->assertOk();
        }
        $this->api('api.storefront.orders.pin-reset.minta', ['phone' => '081252527212'])->assertStatus(429);

        // WA notifikasi mati → pesan arahkan ke WhatsApp admin, tidak dihitung sebagai permintaan.
        Cache::flush();
        $this->wa->tahan = true;
        $this->api('api.storefront.orders.pin-reset.minta', ['phone' => '081252527212'])->assertStatus(503);
        $this->assertNull(Cache::get('pinreset:kode:6281252527212'));
    }

    public function test_rem_per_ip_memakai_ip_pembeli_bukan_ip_server_etalase(): void
    {
        $kirim = fn (string $ip) => $this->withHeaders(['Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json', 'X-Client-IP' => $ip])
            ->postJson(route('api.storefront.orders.pin-reset.minta'), ['phone' => '081200000001']);

        // Pembeli A menghabiskan jatah 5/menit miliknya…
        for ($i = 0; $i < 5; $i++) {
            $kirim('203.0.113.7')->assertNotFound();
        }
        $kirim('203.0.113.7')->assertStatus(429);

        // …pembeli B (IP lain, lewat server etalase yang sama) tidak ikut terkunci.
        $kirim('198.51.100.20')->assertNotFound();
    }
}
