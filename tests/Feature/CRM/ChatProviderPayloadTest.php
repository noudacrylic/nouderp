<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Providers\ApiCoIdProvider;
use App\Modules\CRM\Providers\FakeChatProvider;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 1 modul CRM: bentuk payload ke api.co.id, dan pengaman yang menjaga
 * kita selama pembangunan (saklar jangan-kirim + daftar putih penerima).
 *
 * Tidak menyentuh database maupun jaringan: setting dibuat sebagai model yang
 * tidak disimpan, HTTP dipalsukan. Seluruh berkas ini harus tetap hijau bahkan
 * bila akun api.co.id tidak pernah jadi.
 */
class ChatProviderPayloadTest extends TestCase
{
    private function provider(): ApiCoIdProvider
    {
        // Model sengaja TIDAK disimpan — isConfigured() cukup membaca atribut.
        $setting = new CrmSetting([
            'provider'                => 'apicoid',
            'is_enabled'              => true,
            'api_key'                 => 'kunci-uji',
            'base_url'                => 'https://chat.api.co.id/api/v1/public',
            'default_phone_number_id' => 'nomor-bisnis-1',
        ]);

        return new ApiCoIdProvider($setting);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data'    => ['message_id' => 'msg_1', 'customer_id' => 'cust_1'],
            ], 200),
        ]);
    }

    public function test_nomor_lokal_dinormalkan_ke_kode_negara(): void
    {
        $this->assertSame('628998844666', PhoneNumber::normalize('0899-8844-666'));
        $this->assertSame('628998844666', PhoneNumber::normalize('+62 899 8844 666'));
        $this->assertSame('628998844666', PhoneNumber::normalize('8998844666'));
        $this->assertSame('628998844666', PhoneNumber::normalize('628998844666'));

        // Bentuk '+' hanya milik endpoint broadcast, bukan pengiriman satuan.
        $this->assertSame('+628998844666', PhoneNumber::e164('0899-8844-666'));

        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('123'));
    }

    public function test_template_disusun_sebagai_components_gaya_meta(): void
    {
        $components = ApiCoIdProvider::buildTemplateComponents([
            'body'              => ['Budi Santoso', 'SO-2609-0142', 'AMB-8842', 'Senin–Sabtu 08.00–16.00'],
            'url_button_suffix' => 'a1b2c3d4-token',
        ]);

        // Variabel body = ARRAY parameters (bukan objek datar {"1": "..."};
        // bentuk datar itu hanya dipakai endpoint broadcast yang sengaja
        // tidak kita implementasikan).
        $this->assertSame('body', $components[0]['type']);
        $this->assertSame(
            ['Budi Santoso', 'SO-2609-0142', 'AMB-8842', 'Senin–Sabtu 08.00–16.00'],
            array_column($components[0]['parameters'], 'text')
        );
        $this->assertSame(['text', 'text', 'text', 'text'], array_column($components[0]['parameters'], 'type'));

        // Tombol URL dinamis membawa POTONGAN AKHIR url saja — sisanya sudah
        // tertanam di template saat disetujui Meta.
        $this->assertSame('button', $components[1]['type']);
        $this->assertSame('url', $components[1]['sub_type']);
        $this->assertSame(0, $components[1]['index']);
        $this->assertSame('a1b2c3d4-token', $components[1]['parameters'][0]['text']);
    }

    public function test_header_media_mendahului_body(): void
    {
        $components = ApiCoIdProvider::buildTemplateComponents([
            'header_media' => ['type' => 'image', 'link' => 'https://contoh.id/a.jpg'],
            'body'         => ['Budi'],
        ]);

        $this->assertSame(['header', 'body'], array_column($components, 'type'));
        $this->assertSame('https://contoh.id/a.jpg', $components[0]['parameters'][0]['image']['link']);
    }

    public function test_template_tanpa_tombol_tidak_menyertakan_komponen_button(): void
    {
        $components = ApiCoIdProvider::buildTemplateComponents(['body' => ['Budi']]);

        $this->assertSame(['body'], array_column($components, 'type'));
    }

    public function test_kirim_template_membentuk_payload_yang_benar(): void
    {
        config(['crm.allowed_recipients' => []]);
        $this->fakeOk();

        $hasil = $this->provider()->sendTemplate([
            'to'                => '0899-8844-666',
            'template'          => 'pesanan_siap_diambil',
            'language'          => 'id',
            'body'              => ['Budi', 'SO-1', 'AMB-1', 'Senin–Sabtu 08.00–16.00'],
            'url_button_suffix' => 'token-uuid',
        ]);

        $this->assertTrue($hasil['success']);
        $this->assertSame('msg_1', $hasil['message_id']);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return $req->url() === 'https://chat.api.co.id/api/v1/public/messages/send'
                && $req->hasHeader('Authorization', 'Bearer kunci-uji')
                // Nomor dikirim tanpa '+' pada pengiriman satuan.
                && $body['phone_number'] === '628998844666'
                && $body['message_type'] === 'template'
                && $body['template']['name'] === 'pesanan_siap_diambil'
                && $body['template']['language']['code'] === 'id'
                && $body['whatsapp_phone_number_id'] === 'nomor-bisnis-1'
                && count($body['template']['components']) === 2;
        });
    }

    public function test_daftar_putih_menolak_nomor_asing_tanpa_menyentuh_jaringan(): void
    {
        config(['crm.allowed_recipients' => ['628998844666']]);
        $this->fakeOk();

        $hasil = $this->provider()->sendText([
            'to'   => '628111111111',   // nomor pelanggan asli — tidak boleh lolos
            'text' => 'halo',
        ]);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('daftar putih', $hasil['error']);
        Http::assertNothingSent();
    }

    public function test_saklar_jangan_kirim_menyerahkan_driver_palsu(): void
    {
        config(['crm.dry_run' => true, 'crm.driver' => 'apicoid']);
        Http::fake();

        $manager  = new ChatManager();
        $provider = $manager->provider();

        $this->assertInstanceOf(FakeChatProvider::class, $provider);
        $this->assertFalse($manager->isLive());

        $hasil = $provider->sendTemplate([
            'to'       => '0899-8844-666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '1.500.000', 'SO-1', 'Lunas'],
        ]);

        // Tercatat lengkap, tapi tak sebutir pun keluar.
        $this->assertTrue($hasil['success']);
        Http::assertNothingSent();

        $terkirim = $manager->fake()->lastSent();
        $this->assertSame('template', $terkirim['kind']);
        $this->assertSame('628998844666', $terkirim['to']);
        $this->assertSame('pembayaran_diterima', $terkirim['template']);
        $this->assertSame(
            ['Budi', '1.500.000', 'SO-1', 'Lunas'],
            array_column($terkirim['components'][0]['parameters'], 'text')
        );
    }

    public function test_kegagalan_api_tidak_melempar_exception(): void
    {
        config(['crm.allowed_recipients' => []]);
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'WindowClosed', 'message' => 'Jendela 24 jam tutup']], 400),
        ]);

        $hasil = $this->provider()->sendText(['to' => '628998844666', 'text' => 'halo']);

        $this->assertFalse($hasil['success']);
        $this->assertSame('Jendela 24 jam tutup', $hasil['error']);
        $this->assertNull($hasil['message_id']);
    }

    public function test_provider_belum_dikonfigurasi_gagal_diam_diam(): void
    {
        config(['crm.allowed_recipients' => []]);
        Http::fake();

        $provider = new ApiCoIdProvider(new CrmSetting(['provider' => 'apicoid', 'is_enabled' => false]));

        $hasil = $provider->sendText(['to' => '628998844666', 'text' => 'halo']);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('belum dikonfigurasi', $hasil['error']);
        Http::assertNothingSent();
    }
}
