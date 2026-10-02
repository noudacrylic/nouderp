<?php

namespace Tests\Unit;

use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Shipping\Services\MapsLinkExpander;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapsLinkExpanderTest extends TestCase
{
    public function test_link_pendek_dibuka_sampai_koordinat_terbaca(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/Toko/@-6.81,107.58,14z/data=!3d-6.8180474!4d107.6237886',
            ]),
        ]);

        $url = (new MapsLinkExpander)->buka('https://maps.app.goo.gl/abc');

        $this->assertSame(-6.8180474, parse_lat_long($url)['latitude']);
    }

    public function test_redirect_ke_luar_google_tidak_diikuti(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        ]);

        $this->assertNull((new MapsLinkExpander)->buka('https://maps.app.goo.gl/abc'));
        Http::assertSentCount(1);
    }

    public function test_nama_tempat_dari_link_tanpa_koordinat(): void
    {
        $this->assertSame(
            'Majlis An Nur, Mranggen',
            MapsLinkExpander::namaTempat('https://www.google.com/maps?q=Majlis+An+Nur,+Mranggen&ftid=0x1:0x2')
        );
        $this->assertSame(
            'BSB City Gas Station, Mijen',
            MapsLinkExpander::namaTempat('https://www.google.com/maps/place/BSB+City+Gas+Station,+Mijen/data=!4m2')
        );
    }

    public function test_link_share_google_dicari_titik_tempatnya_lewat_kgmid(): void
    {
        $tempat = 'https://www.google.com/search?kgmid=/g/11zh97rpt3&q=Kidinest&hl=en-id';

        Http::fake([
            'share.google/*' => Http::response('', 302, ['Location' => 'https://www.google.com/share.google?q=abc']),
            'www.google.com/share.google*' => Http::response('', 302, ['Location' => $tempat]),
            'www.google.com/maps?*' => Http::response('<head><link href="/search?tbm=map&amp;q=Kidinest&amp;pb=x" as="fetch"></head>'),
            'www.google.com/search?tbm=map*' => Http::response(
                // Entri lain lebih dulu: titiknya tak boleh terambil.
                ')]}\'[[null,null,-7.1,110.1],"0xaa:0xbb","Kidinest Lain"],'
                . '[null,null,-6.9825485,110.4225633],"0x2e708b0027f5b961:0x69b6fad2bf6524cf","Kidinest",'
                . '[null,null,-6.98266,110.42255],"/g/11zh97rpt3"'
            ),
        ]);

        $e = new MapsLinkExpander;
        $this->assertTrue(MapsLinkExpander::linkPendek('https://share.google/oC2N4XpKVVoFhToui'));

        $panjang = $e->buka('https://share.google/oC2N4XpKVVoFhToui');
        $this->assertSame($tempat, $panjang);
        $this->assertSame(['latitude' => -6.9825485, 'longitude' => 110.4225633], $e->titikTempat($panjang));
    }

    public function test_titik_tempat_tidak_menebak_bila_kgmid_tak_ditemukan(): void
    {
        Http::fake([
            'www.google.com/maps?*' => Http::response('<link href="/search?tbm=map&amp;q=X">'),
            'www.google.com/search?tbm=map*' => Http::response('[null,null,-7.1,110.1],"0xaa:0xbb","Kidinest Lain"'),
        ]);

        $e = new MapsLinkExpander;
        $this->assertNull($e->titikTempat('https://www.google.com/search?kgmid=/g/11zh97rpt3&q=Kidinest'));
        // Tanpa kgmid (cuma nama) tak dicari sama sekali — nama bisa kembar.
        $this->assertNull($e->titikTempat('https://www.google.com/maps?q=Kidinest'));
        // Bukan domain Google: tak dikunjungi.
        $this->assertNull($e->titikTempat('https://evil.test/search?kgmid=/g/1&q=X'));
        Http::assertSentCount(2);
    }

    public function test_titik_peta_dari_shareloc_link_dan_ketikan(): void
    {
        $lokasi = new CrmMessage(['raw' => ['location' => ['latitude' => '-6.93', 'longitude' => '110.26']]]);
        $link   = new CrmMessage(['content' => 'ini kak https://maps.app.goo.gl/wTmoq.']);
        $ketik  = new CrmMessage(['content' => 'titiknya -7.0123, 110.4567 ya']);
        $biasa  = new CrmMessage(['content' => 'harga 1.500, ukuran 2.5']);
        $share  = new CrmMessage(['content' => 'https://share.google/oC2N4XpKVVoFhToui']);

        $this->assertSame('-6.93,110.26', $lokasi->titikPeta());
        $this->assertSame('https://maps.app.goo.gl/wTmoq', $link->titikPeta());
        $this->assertSame('-7.0123,110.4567', $ketik->titikPeta());
        $this->assertNull($biasa->titikPeta());
        $this->assertSame('https://share.google/oC2N4XpKVVoFhToui', $share->titikPeta());
    }
}
