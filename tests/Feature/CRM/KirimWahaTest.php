<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Providers\ApiCoIdProvider;
use App\Modules\CRM\Providers\WahaChatProvider;
use App\Modules\CRM\Services\CrmReplyService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TAHAP 7: membalas pelanggan dari ERP lewat nomor UTAMA (WAHA).
 *
 * Yang dijaga di sini dipilih dari bentuk kegagalannya, sama seperti Tahap 6 —
 * semuanya kerusakan yang tak menimbulkan galat saat terjadi, dan yang
 * akibatnya sampai ke HP pelanggan sehingga tak bisa ditarik kembali:
 *
 *  - Balasan yang berangkat lewat jalur YANG SALAH mendarat sebagai pesan dari
 *    nomor asing, terputus dari percakapan yang sedang dibicarakan.
 *  - Saklar jangan-kirim yang bocor lewat routing per percakapan membatalkan
 *    seluruh gunanya sebagai pengaman terakhir.
 *  - sendSeen yang jalan otomatis memberi centang biru pada chat yang cuma
 *    diintip.
 *  - Pesan yang berangkat saat sesi mati ditandai "terkirim" padahal tidak.
 */
class KirimWahaTest extends TestCase
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
            'config'     => [
                'sesi' => [
                    'utama'      => ['session' => 'utama'],
                    'notifikasi' => ['session' => 'notifikasi'],
                ],
            ],
        ])->save();

        // Saklar jangan-kirim dimatikan: yang diuji justru pengirimannya.
        // Tiap tes yang ingin menguji saklarnya menyalakannya sendiri.
        config()->set('crm.dry_run', false);
        config()->set('crm.allowed_recipients', []);
    }

    private function cermin(string $nomor = '6281234000111'): CrmConversation
    {
        return CrmConversation::findOrCreateFor($nomor, CrmConversation::KANAL_CERMIN);
    }

    private function resmi(string $nomor = '6281234000222'): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor, CrmConversation::KANAL_RESMI);
        $p->forceFill(['window_expires_at' => now()->addHours(5)])->save();

        return $p;
    }

    /** Sesi WORKING + satu jawaban kirim yang berhasil. */
    private function wahaSehat(array $jawabanKirim = ['id' => 'ABC123']): void
    {
        Http::fake([
            '*/api/sessions/utama' => Http::response(['status' => 'WORKING'], 200),
            '*/api/send*'          => Http::response($jawabanKirim, 201),
        ]);
    }

    /* ------------------------------------------------ pemilihan jalur */

    /**
     * Jalur dipilih PERCAKAPAN, bukan saklar global.
     *
     * Ini inti Tahap 7. Satu saklar global berarti saat ia digeser, thread lama
     * ikut pindah jalur — dan balasan atas chat yang masuk ke nomor A berangkat
     * dari nomor B, yang di HP pelanggan tampak sebagai orang asing menimpali
     * percakapan yang tak pernah ia kirim ke situ.
     */
    public function test_jalur_dipilih_per_percakapan_bukan_saklar_global(): void
    {
        $chat = app(ChatManager::class);

        $this->assertInstanceOf(WahaChatProvider::class, $chat->untuk($this->cermin()));
        $this->assertInstanceOf(ApiCoIdProvider::class, $chat->untuk($this->resmi()));
    }

    /**
     * Saklar jangan-kirim tetap di ATAS routing per percakapan.
     *
     * Routing baru membuka jalan kedua ke jaringan; kalau saklarnya cuma
     * diperiksa di provider() yang lama, jalan baru itu melewatinya tanpa
     * gejala apa pun.
     */
    public function test_saklar_jangan_kirim_menang_atas_routing_percakapan(): void
    {
        config()->set('crm.dry_run', true);

        $chat = app(ChatManager::class);

        $this->assertNotInstanceOf(WahaChatProvider::class, $chat->untuk($this->cermin()));
        $this->assertFalse($chat->siapUntuk($this->cermin()));
    }

    /** Balasan thread nomor utama benar-benar berangkat ke endpoint WAHA. */
    public function test_balasan_cermin_berangkat_lewat_waha(): void
    {
        $this->wahaSehat();

        $hasil = app(CrmReplyService::class)->balas($this->cermin(), 'halo, ini dari ERP');

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/api/sendText')
                && $req['session'] === 'utama'
                && $req['chatId'] === '6281234000111@c.us'
                && $req['text']   === 'halo, ini dari ERP';
        });
    }

    /**
     * Id pesan keluar diberi awalan `waha:` — sama seperti yang dipasang cermin
     * pada pesan MASUK.
     *
     * Bukan kerapian: webhook `message.ack` yang datang kemudian mencari
     * barisnya dengan awalan itu. Tanpa dipasang di sini, centang pesan yang
     * KITA kirim tak pernah bergerak dari "terkirim", dan tak ada galat apa pun
     * yang menunjukkan kenapa.
     */
    public function test_id_pesan_keluar_berawalan_waha_supaya_centang_bisa_menyusul(): void
    {
        $this->wahaSehat(['id' => 'XYZ789']);

        app(CrmReplyService::class)->balas($this->cermin(), 'tes centang');

        $this->assertSame(
            'waha:XYZ789',
            CrmMessage::where('direction', CrmMessage::KELUAR)->value('provider_message_id')
        );
    }

    /**
     * Kutipan: id yang disimpan ERP berawalan `waha:`, yang dikenal WAHA id
     * aslinya. Tanpa pengupasan, kutipan ditolak diam-diam dan pesannya
     * berangkat tanpa konteks yang sengaja dipilih admin.
     */
    public function test_awalan_dikupas_lagi_saat_mengutip(): void
    {
        $this->wahaSehat();

        app(CrmReplyService::class)->balas($this->cermin(), 'jawaban', null, 'waha:PESAN_ASAL');

        Http::assertSent(fn ($req) => ($req['reply_to'] ?? null) === 'PESAN_ASAL');
    }

    /* ------------------------------------------------------- sesi mati */

    /**
     * Sesi yang tidak WORKING = TIDAK mengirim, dan galatnya menyebut apa yang
     * harus dikerjakan.
     *
     * Yang paling berbahaya bukan gagalnya melainkan baris yang terlanjur
     * ditulis sebagai "terkirim": admin mengira pelanggan sudah dijawab, lalu
     * menunggu balasan yang tak akan pernah datang.
     */
    public function test_sesi_mati_tidak_mengirim_dan_tidak_menulis_baris_keluar(): void
    {
        Http::fake([
            '*/api/sessions/utama' => Http::response(['status' => 'SCAN_QR_CODE'], 200),
            '*/api/send*'          => Http::response(['id' => 'TIDAK_BOLEH'], 201),
        ]);

        $hasil = app(CrmReplyService::class)->balas($this->cermin(), 'halo');

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('SCAN_QR_CODE', (string) $hasil['error']);
        $this->assertSame(0, CrmMessage::where('direction', CrmMessage::KELUAR)->count());

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/sendText'));
    }

    /* --------------------------------------------------- tandai dibaca */

    /**
     * sendSeen TIDAK jalan selama saklarnya mati — dan bawaannya memang mati.
     *
     * Harganya sampai ke pelanggan: centang biru. Chat yang cuma diintip di ERP
     * tidak boleh terbaca sebagai "sudah dilihat".
     */
    public function test_tandai_dibaca_mati_secara_bawaan(): void
    {
        $this->wahaSehat();

        $hasil = app(CrmReplyService::class)->tandaiDibaca($this->cermin());

        $this->assertFalse($hasil['success']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/sendSeen'));
    }

    /** Dinyalakan di Pengaturan, tombolnya bekerja. */
    public function test_tandai_dibaca_bekerja_saat_saklarnya_menyala(): void
    {
        config()->set('crm.waha.tandai_dibaca', true);
        $this->wahaSehat();

        $hasil = app(CrmReplyService::class)->tandaiDibaca($this->cermin());

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/sendSeen')
            && $req['chatId'] === '6281234000111@c.us'
            && $req['session'] === 'utama');
    }

    /**
     * Jalur RESMI tidak punya notifikasi HP untuk dibersihkan — nomornya
     * dicabut dari aplikasi WhatsApp oleh Cloud API. Menyalakannya di sana
     * berarti memanggil sesi WAHA untuk percakapan yang bukan miliknya.
     */
    public function test_tandai_dibaca_ditolak_di_thread_jalur_resmi(): void
    {
        config()->set('crm.waha.tandai_dibaca', true);

        $hasil = app(CrmReplyService::class)->tandaiDibaca($this->resmi());

        $this->assertFalse($hasil['success']);
    }

    /* ----------------------------------------------- pagar yang tersisa */

    /**
     * Daftar putih penerima berlaku di jalur ini JUGA.
     *
     * Selama uji nyata, satu salah ketik nomor berarti orang asing menerima
     * pesan dari nomor toko — dan dari nomor UTAMA, akibatnya melekat pada
     * identitas yang tak tergantikan.
     */
    public function test_daftar_putih_menahan_nomor_asing(): void
    {
        config()->set('crm.allowed_recipients', ['628999999999']);
        $this->wahaSehat();

        $hasil = app(CrmReplyService::class)->balas($this->cermin(), 'halo');

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('daftar putih', strtolower((string) $hasil['error']));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/sendText'));
    }

    /**
     * Tombol balasan cepat ditolak TEGAS, bukan diturunkan diam-diam jadi teks.
     *
     * Pemanggil satu-satunya adalah pancingan jendela 24 jam. Kalau jalur ini
     * menerimanya sebagai teks biasa, pancingan "berhasil" — pelanggan diganggu
     * tanpa satu pun alasan yang membuat fiturnya ada, karena jendela yang mau
     * dibuka lagi itu memang tidak pernah ada di sini.
     */
    public function test_tombol_balasan_cepat_ditolak_di_jalur_self_host(): void
    {
        $hasil = app(WahaChatProvider::class)->sendInteraktif([
            'to'      => '6281234000111',
            'text'    => 'halo',
            'buttons' => [['id' => 'x', 'title' => 'Lanjut']],
        ]);

        $this->assertFalse($hasil['success']);
    }

    /** Jendela 24 jam tidak berlaku — dan jawabannya jujur, bukan siasat. */
    public function test_jendela_selalu_terbuka_di_jalur_self_host(): void
    {
        $status = app(WahaChatProvider::class)->windowStatus('6281234000111');

        $this->assertTrue($status['success']);
        $this->assertTrue($status['is_open']);
    }

    /* ------------------------------------------------- gema pesan sendiri */

    /**
     * Balasan yang KITA kirim kembali lagi lewat webhook sebagai `fromMe` —
     * dan tidak boleh melahirkan gelembung kedua.
     *
     * Bentuk idnya sengaja dibuat BERBEDA antara jawaban kirim ('3EB0RESP')
     * dan webhook ('true_628…@c.us_3EB0RESP'), karena itulah yang terjadi
     * sungguhan: WAHA menjawab dengan id pendek lalu mengabarkan yang panjang.
     * Kalau dedupe-nya cuma mencocokkan persis, SETIAP balasan muncul dua kali
     * di thread tanpa satu pun galat yang menunjukkan kenapa.
     */
    public function test_gema_balasan_sendiri_tidak_jadi_gelembung_kedua(): void
    {
        CrmSetting::for('waha')->forceFill([
            'config' => array_merge((array) CrmSetting::for('waha')->config, [
                'webhook_url_token' => 'token-gema-uji-0000000000000000000000',
            ]),
        ])->save();

        $this->wahaSehat(['id' => '3EB0RESP']);

        $percakapan = $this->cermin();
        app(CrmReplyService::class)->balas($percakapan, 'balasan dari ERP');

        $this->assertSame(1, CrmMessage::count());

        // Gema dari WAHA, dengan bentuk id yang panjang.
        $this->postJson('/crm/waha/webhook/token-gema-uji-0000000000000000000000', [
            'event'   => 'message.any',
            'session' => 'utama',
            'me'      => ['id' => '628998844666@c.us'],
            'payload' => [
                'id'        => 'true_6281234000111@c.us_3EB0RESP',
                'timestamp' => 1758700000,
                'from'      => '6281234000111@c.us',
                'fromMe'    => true,
                'to'        => '6281234000111@c.us',
                'body'      => 'balasan dari ERP',
                'hasMedia'  => false,
            ],
        ])->assertOk();

        $this->assertSame(1, CrmMessage::count(), 'gema diserap, bukan jadi gelembung kedua');

        // Id dikanonikalkan ke bentuk webhook, supaya `message.ack` yang
        // menyusul menemukan barisnya dan centangnya bisa bergerak.
        $this->assertSame(
            'waha:true_6281234000111@c.us_3EB0RESP',
            CrmMessage::sole()->provider_message_id
        );
    }

    /**
     * Notifikasi TIDAK boleh berangkat dari nomor utama, dan pagar itu masih
     * berdiri setelah adapter kedua lahir.
     *
     * Seluruh alasan dua nomor dipisah adalah supaya yang menanggung risiko
     * blokir bukan identitas toko. Tahap 7 menambah satu kelas yang memegang
     * sesi `utama`; kalau pagar lama ikut longgar tanpa ada yang memeriksa,
     * kerugiannya baru ketahuan setelah nomornya diblokir.
     */
    public function test_notifikasi_tetap_tidak_boleh_lewat_nomor_utama(): void
    {
        $waha = new \App\Modules\CRM\Providers\WahaProvider(
            CrmSetting::for('waha'),
            \App\Modules\CRM\Support\PeranWaha::UTAMA
        );

        $hasil = $waha->kirimNotifikasi([
            'to'       => '6281234000111',
            'template' => 'pesanan_dikirim',
            'body'     => ['A', 'B'],
        ]);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('notifikasi hanya boleh', (string) $hasil['error']);
    }
}
