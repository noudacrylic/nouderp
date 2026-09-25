<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\Customer;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmWebhookEvent;
use App\Modules\CRM\Services\CrmReplyService;
use App\Modules\CRM\Services\WebhookHealthService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cermin baca-saja: chat nomor utama lewat WAHA masuk ke Inbox, tanpa
 * menyentuh apa pun yang lain.
 *
 * Yang dijaga di sini dipilih berdasarkan bentuk kegagalannya: semuanya
 * kerusakan yang TIDAK terlihat dari layar mana pun. Cermin yang ikut membuka
 * jendela 24 jam menyalakan kotak ketik yang pasti ditolak saat dipakai.
 * Cermin yang menulis ke thread resmi membuat balasan berangkat dari nomor
 * yang salah. Cermin yang mengisi crm_webhook_events tanpa penanda membuat
 * pita "webhook resmi sepi" tidak pernah menyala lagi. Tak satu pun dari
 * ketiganya menimbulkan galat saat terjadi.
 */
class CerminWahaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-cermin-uji-000000000000000000000';

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
                'webhook_url_token' => self::TOKEN,
                'sesi' => [
                    'utama'      => ['session' => 'utama'],
                    'notifikasi' => ['session' => 'notifikasi'],
                ],
            ],
        ])->save();
    }

    /** Satu peristiwa `message.any` apa adanya dari WAHA (engine NOWEB). */
    private function payload(array $ganti = [], array $pesan = []): array
    {
        return array_merge([
            'id'        => 'evt_' . uniqid(),
            'timestamp' => 1758700000000,
            'event'     => 'message.any',
            'session'   => 'utama',
            'me'        => ['id' => '628998844666@c.us', 'pushName' => 'Noud Acrylic'],
            'engine'    => 'NOWEB',
            'payload'   => array_merge([
                'id'         => 'false_628123456789@c.us_3EB0ABCDEF',
                'timestamp'  => 1758700000,
                'from'       => '628123456789@c.us',
                'fromMe'     => false,
                'to'         => '628998844666@c.us',
                'body'       => 'Halo kak, acrylic 5mm ada?',
                'hasMedia'   => false,
                'notifyName' => 'Budi',
            ], $pesan),
        ], $ganti);
    }

    private function kirim(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/crm/waha/webhook/' . self::TOKEN, $payload);
    }

    /* ------------------------------------------------------------- penjaga */

    public function test_token_salah_ditolak(): void
    {
        $this->postJson('/crm/waha/webhook/token-ngawur', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, CrmMessage::count());
    }

    /**
     * Tanpa token tersimpan, endpoint menolak — BUKAN menerima apa adanya.
     * Endpoint ini menulis percakapan dan mengunduh berkas dari alamat yang
     * disebut pengirim; kalau boleh dipanggil tanpa penjaga, siapa pun bisa
     * mengarang chat pelanggan.
     */
    public function test_tanpa_token_tersimpan_endpoint_menolak(): void
    {
        $waha = CrmSetting::for('waha');
        $waha->forceFill(['config' => array_merge((array) $waha->config, ['webhook_url_token' => ''])])->save();

        $this->kirim($this->payload())->assertStatus(503);
    }

    public function test_hmac_diperiksa_bila_rahasianya_dipasang(): void
    {
        $waha = CrmSetting::for('waha');
        $waha->forceFill(['config' => array_merge((array) $waha->config, ['webhook_hmac' => 'rahasia'])])->save();

        $payload = $this->payload();
        $raw     = json_encode($payload);

        // Token benar tapi tanda tangan salah: tetap ditolak. Penjaganya
        // bertumpuk, bukan berganti.
        $this->call('POST', '/crm/waha/webhook/' . self::TOKEN, [], [], [], [
            'CONTENT_TYPE'                 => 'application/json',
            'HTTP_ACCEPT'                  => 'application/json',
            'HTTP_X_WEBHOOK_HMAC'          => 'jelas-salah',
            'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
        ], $raw)->assertStatus(403);

        // ⚠️ SHA-512, bukan SHA-256 seperti webhook api.co.id sebelah.
        $this->call('POST', '/crm/waha/webhook/' . self::TOKEN, [], [], [], [
            'CONTENT_TYPE'                 => 'application/json',
            'HTTP_ACCEPT'                  => 'application/json',
            'HTTP_X_WEBHOOK_HMAC'          => hash_hmac('sha512', $raw, 'rahasia'),
            'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
        ], $raw)->assertOk();

        $this->assertSame(1, CrmMessage::count());
    }

    /* -------------------------------------------------------------- cermin */

    public function test_pesan_masuk_jadi_percakapan_kanal_cermin(): void
    {
        $this->kirim($this->payload())->assertOk();

        $percakapan = CrmConversation::sole();

        $this->assertSame(CrmConversation::KANAL_CERMIN, $percakapan->channel);
        $this->assertSame('628123456789', $percakapan->contact_key);
        $this->assertSame('Budi', $percakapan->display_name);
        $this->assertTrue($percakapan->cermin());

        $pesan = CrmMessage::sole();

        $this->assertSame(CrmMessage::MASUK, $pesan->direction);
        $this->assertSame('Halo kak, acrylic 5mm ada?', $pesan->content);
        $this->assertSame('waha:false_628123456789@c.us_3EB0ABCDEF', $pesan->provider_message_id);
    }

    /**
     * INTI Tahap 6. Cermin tidak memindahkan antrean, dan yang paling
     * menentukan: tidak membuka jendela 24 jam. Jendela itu milik jalur
     * berbayar; menuliskannya di sini berarti menyalakan kotak ketik yang
     * pasti ditolak saat dipakai.
     *
     * `unread_count` SENGAJA dikecualikan dari daftar ini sejak lencana
     * belum-dibaca dinyalakan — lihat test_pesan_masuk_live_menaikkan_belum_dibaca.
     * Tanpa penanda apa pun, chat yang baru masuk tak bisa dibedakan dari
     * ratusan thread lama di daftar.
     */
    public function test_cermin_tidak_menyentuh_antrean_maupun_jendela(): void
    {
        $this->kirim($this->payload())->assertOk();

        $percakapan = CrmConversation::sole();

        $this->assertNull($percakapan->window_expires_at);
        $this->assertFalse($percakapan->windowIsOpen());
        // Bukan 'menunggu_kita': itu bawaan kolomnya, dan membiarkannya
        // berarti tiap thread cermin menumpuk di antrean triase sebagai
        // pekerjaan yang tak punya tombol.
        $this->assertSame(CrmConversation::QUEUE_DINGIN, $percakapan->queue_state);

        // Yang BOLEH bergerak cuma ini — tanpanya thread membeku di dasar
        // daftar yang diurutkan COALESCE(last_message_at, created_at).
        $this->assertNotNull($percakapan->last_message_at);
    }

    /**
     * Thread cermin dan thread resmi milik nomor yang sama tetap terpisah.
     * Kalau melebur, balasan yang diketik di ERP berangkat lewat jalur resmi
     * menanggapi chat yang masuk ke nomor lain — pelanggan menerima jawaban
     * dari nomor asing, dan itu tak bisa ditarik kembali.
     */
    public function test_thread_resmi_nomor_yang_sama_tidak_ikut_tersentuh(): void
    {
        $resmi = CrmConversation::findOrCreateFor('628123456789');
        $resmi->forceFill([
            'unread_count'      => 3,
            'window_expires_at' => now()->addHours(5),
        ])->save();

        $this->kirim($this->payload())->assertOk();

        $resmi->refresh();

        $this->assertSame(3, $resmi->unread_count);
        $this->assertSame(0, $resmi->messages()->count());
        $this->assertSame(2, CrmConversation::count());
    }

    public function test_pesan_dari_hp_kita_ikut_tercermin_sebagai_keluar(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'     => 'true_628123456789@c.us_3EB0AAA',
            'fromMe' => true,
            'from'   => '628998844666@c.us',
            'to'     => '628123456789@c.us',
            'body'   => 'Ada kak, 5mm bening.',
        ]))->assertOk();

        $pesan = CrmMessage::sole();

        $this->assertSame(CrmMessage::KELUAR, $pesan->direction);
        // Sumbernya HP, bukan ERP — laporan "kebocoran balas dari HP" harus
        // tetap jujur, dan ERP memang belum bisa mengirim ke kanal ini.
        $this->assertSame(CrmMessage::SOURCE_WHATSAPP_APP, $pesan->source);
        $this->assertSame('628123456789', CrmConversation::sole()->contact_key);
    }

    public function test_pesan_kembar_tidak_digandakan(): void
    {
        $payload = $this->payload();

        $this->kirim($payload)->assertOk();

        // Peristiwa berbeda ('message' setelah 'message.any'), amplop berbeda,
        // pesan yang sama: tetap satu baris.
        $this->kirim(array_merge($payload, ['id' => 'evt_lain', 'event' => 'message']))->assertOk();

        $this->assertSame(1, CrmMessage::count());
    }

    public function test_media_dicatat_tanpa_diunduh_saat_webhook(): void
    {
        $this->kirim($this->payload(pesan: [
            'body'     => 'ini desainnya',
            'hasMedia' => true,
            'media'    => [
                'url'      => 'http://127.0.0.1:3000/api/files/abc.jpeg',
                'mimetype' => 'image/jpeg',
                'filename' => 'desain.jpeg',
            ],
        ]))->assertOk();

        $lampiran = CrmMessage::sole()->attachments()->sole();

        $this->assertSame('http://127.0.0.1:3000/api/files/abc.jpeg', $lampiran->source_url);
        $this->assertSame('desain.jpeg', $lampiran->original_name);
        // Tidak diunduh di dalam permintaan: WAHA menunggu, menganggap gagal,
        // lalu mengulang. Http::preventStrayRequests() yang menjaganya.
        $this->assertNull($lampiran->downloaded_at);
    }

    /**
     * Bentuk id NOWEB ('@s.whatsapp.net') diterima sama seperti '@c.us'.
     *
     * DIVERIFIKASI di server 24 Sep 2026: endpoint /chats mengembalikan 643
     * dari 656 chat dengan akhiran @s.whatsapp.net. Menerima satu bentuk saja
     * berarti pesan dibuang DIAM-DIAM — inbox terlihat seperti hari sepi, dan
     * tak ada satu pun baris gagal yang bisa dicari.
     */
    public function test_bentuk_id_noweb_juga_diterima(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'   => 'false_628123456789@s.whatsapp.net_3EB0NOWEB',
            'from' => '628123456789@s.whatsapp.net',
            'to'   => '628998844666@s.whatsapp.net',
        ]))->assertOk();

        $this->assertSame('628123456789', CrmConversation::sole()->contact_key);
        $this->assertSame(1, CrmMessage::count());
    }

    /**
     * Chat beralamat LID: `from` berisi '@lid', nomor aslinya di
     * `_data.key.remoteJidAlt`. Pesan dari HP kita datang dengan `to` KOSONG.
     *
     * DIVERIFIKASI di server 24 Sep 2026: seluruh 40 pesan perorangan hari
     * itu berbentuk begini, dan semuanya dibuang diam-diam oleh saringan.
     */
    public function test_chat_lid_dipetakan_ke_nomor_asli_lewat_remote_jid_alt(): void
    {
        $kunci = fn (bool $dariKita, string $idKunci) => ['key' => [
            'remoteJid'      => '145092712345072@lid',
            'remoteJidAlt'   => '628123456789@s.whatsapp.net',
            'fromMe'         => $dariKita,
            'id'             => $idKunci,
            'participant'    => null,
            'addressingMode' => 'lid',
        ]];

        $this->kirim($this->payload(pesan: [
            'id'    => 'false_145092712345072@lid_3AMASUK',
            'from'  => '145092712345072@lid',
            'to'    => null,
            '_data' => $kunci(false, '3AMASUK'),
        ]))->assertOk();

        $this->kirim($this->payload(pesan: [
            'id'     => 'true_145092712345072@lid_3AKELUAR',
            'fromMe' => true,
            'from'   => '145092712345072@lid',
            'to'     => null,
            'body'   => 'Ada kak.',
            '_data'  => $kunci(true, '3AKELUAR'),
        ]))->assertOk();

        $this->assertSame('628123456789', CrmConversation::sole()->contact_key);
        $this->assertSame(
            [CrmMessage::MASUK, CrmMessage::KELUAR],
            CrmMessage::orderBy('id')->pluck('direction')->all()
        );
    }

    /** "Kirim ke diri sendiri" di HP nomor utama bukan thread pelanggan. */
    public function test_chat_ke_nomor_sendiri_diabaikan(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'     => 'true_628998844666@c.us_3ASENDIRI',
            'fromMe' => true,
            'from'   => '628998844666@c.us',
            'to'     => null,
            '_data'  => ['key' => ['remoteJid' => '628998844666@s.whatsapp.net', 'fromMe' => true]],
        ]))->assertOk();

        $this->assertSame(0, CrmConversation::count());
    }

    /** Status berkunci NOWEB: remoteJidAlt berisi nomor pengirim, tetap ditolak. */
    public function test_status_dengan_kunci_noweb_tetap_diabaikan(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'    => 'false_status@broadcast_3ASTATUS',
            'from'  => 'status@broadcast',
            '_data' => ['key' => [
                'remoteJid'    => 'status@broadcast',
                'remoteJidAlt' => '628123456789@s.whatsapp.net',
                'participant'  => '145092712345072@lid',
            ]],
        ]))->assertOk();

        $this->assertSame(0, CrmMessage::count());
    }

    /**
     * Grup, status, dan newsletter TIDAK dicermin. Nomor utama ada di grup
     * internal, dan tanpa saringan ini isinya tumpah ke layar yang dibuka
     * seluruh tim CS.
     */
    public function test_grup_dan_status_diabaikan(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'   => 'false_62811@g.us_XX',
            'from' => '628123456789-1600000000@g.us',
        ]))->assertOk();

        $this->kirim($this->payload(pesan: [
            'id'   => 'false_status_YY',
            'from' => 'status@broadcast',
        ]))->assertOk();

        // '@lid' bukan nomor telepon sama sekali — kalau lolos, ia melahirkan
        // percakapan bernomor palsu yang tak pernah bisa dihubungi.
        $this->kirim($this->payload(pesan: [
            'id'   => 'false_lid_ZZ',
            'from' => '197412345678901@lid',
        ]))->assertOk();

        $this->assertSame(0, CrmConversation::count());
        $this->assertSame(0, CrmMessage::count());
    }

    /** Sesi notifikasi punya jawaban otomatisnya sendiri; ia tidak dicermin. */
    public function test_sesi_selain_utama_diabaikan(): void
    {
        $this->kirim($this->payload(['session' => 'notifikasi']))->assertOk();

        $this->assertSame(0, CrmMessage::count());
    }

    public function test_nomor_dicocokkan_ke_master_pelanggan(): void
    {
        $customer = Customer::create([
            'code'      => 'CUST-CERMIN',
            'name'      => 'Budi Santoso',
            'phone'     => '0812-3456-789',
            'is_active' => true,
        ]);

        $this->kirim($this->payload())->assertOk();

        $this->assertSame($customer->id, CrmConversation::sole()->customer_id);
    }

    /* ------------------------------------------------------- efek ke sekitar */

    /**
     * BALASAN lewat WAHA tidak boleh MEMVONIS jalur resmi mati.
     *
     * Sisi sebaliknya dari tes di bawah, dan sama pentingnya. Timbangannya
     * harus seimbang: kabar balik WAHA dikecualikan, jadi kirimannya pun
     * wajib dikecualikan. Kalau tidak, satu balasan lewat nomor utama
     * membuktikan "ada yang dikirim", tak ada kabar resmi yang menyusul, lalu
     * jalur resmi divonis mati padahal ia cuma sedang tidak dipakai.
     *
     * Sejak Tahap 7 hampir semua balasan berangkat lewat WAHA, jadi tanpa
     * pengecualian ini pitanya menyala selamanya — dan pita yang selalu
     * menyala sama saja dengan tidak ada pita, justru pada kerusakan yang
     * satu-satunya gejalanya adalah pita itu.
     */
    public function test_balasan_lewat_waha_tidak_memvonis_jalur_resmi_mati(): void
    {
        $this->kirim($this->payload())->assertOk();

        $cermin = CrmConversation::sole();

        CrmMessage::create([
            'conversation_id' => $cermin->id,
            'direction'       => CrmMessage::KELUAR,
            'message_type'    => 'text',
            'content'         => 'balasan dari ERP lewat nomor utama',
            'status'          => 'terkirim',
            'sent_at'         => now()->subHour(),
        ]);

        $this->assertNull(
            app(WebhookHealthService::class)->sepi(),
            'balasan lewat WAHA bukan bukti jalur resmi patah'
        );
    }

    /**
     * Baris cermin tidak boleh dihitung sebagai bukti jalur RESMI masih hidup.
     * Kalau ikut dihitung, satu chat WAHA akan menyatakan webhook resmi sehat
     * padahal ia mati total — dan pitanya tidak pernah menyala lagi.
     */
    public function test_cermin_tidak_memalsukan_kesehatan_webhook_resmi(): void
    {
        $percakapan = CrmConversation::findOrCreateFor('628111222333');

        CrmMessage::create([
            'conversation_id' => $percakapan->id,
            'direction'       => CrmMessage::KELUAR,
            'message_type'    => 'text',
            'content'         => 'halo',
            'status'          => 'terkirim',
            'sent_at'         => now()->subHour(),
        ]);

        $this->assertNotNull(app(WebhookHealthService::class)->sepi(), 'prasyarat: jalur resmi memang sedang sepi');

        $this->kirim($this->payload())->assertOk();

        $this->assertGreaterThan(0, CrmWebhookEvent::count(), 'peristiwanya tetap dicatat untuk bisa diputar ulang');
        $this->assertNotNull(
            app(WebhookHealthService::class)->sepi(),
            'cermin WAHA tidak boleh menutupi jalur resmi yang mati'
        );
    }

    /**
     * SEJAK TAHAP 7 thread nomor utama BISA dibalas dari ERP — kebalikan dari
     * Tahap 6, dan pembalikan itu disengaja.
     *
     * Yang diuji di sini bukan sekadar "tidak ditolak", melainkan bahwa
     * jendela 24 jam TIDAK ikut menghalangi. Jendela sengaja ditutup rapat:
     * kalau penjaganya masih berlaku di jalur ini, balasan ditolak — padahal
     * jendela itu aturan penagihan Meta atas Cloud API, dan jalur ini bukan
     * Cloud API. CS yang mengetik dari HP memang bisa membalas chat setahun
     * lalu; ERP tidak boleh lebih penakut dari HP.
     */
    public function test_thread_cermin_bisa_dibalas_dan_tidak_terikat_jendela(): void
    {
        $this->kirim($this->payload())->assertOk();

        $percakapan = CrmConversation::sole();

        $percakapan->forceFill(['window_expires_at' => now()->subDays(30)])->save();

        $hasil = app(CrmReplyService::class)->balas($percakapan, 'balasan dari ERP');

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));
        $this->assertSame(2, CrmMessage::count(), 'baris keluarnya tertulis di thread');
    }

    /**
     * Pancingan TETAP ditolak, dan alasannya berubah total dari Tahap 6.
     *
     * Dulu: thread ini tidak boleh dikirimi apa pun. Sekarang: seluruh guna
     * pancingan adalah membuka lagi jendela 24 jam yang mau habis, dan jalur
     * ini tidak punya jendela. Yang tersisa cuma mengganggu orang tanpa satu
     * pun alasan yang membuat fiturnya ada.
     */
    public function test_thread_cermin_menolak_pancingan_karena_tak_punya_jendela(): void
    {
        $this->kirim($this->payload())->assertOk();

        $hasil = app(CrmReplyService::class)->kirimPancingan(CrmConversation::sole(), null, true);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('jendela 24 jam', strtolower((string) $hasil['error']));
    }

    /**
     * Kunci WAHA tetap ikut walau host URL-nya 'localhost' dan base_url kita
     * '127.0.0.1'.
     *
     * DIVERIFIKASI di server 24 Sep 2026: WAHA merangkai alamat media dari
     * alamat publiknya sendiri, bukan dari yang diketik di Pengaturan —
     * hasilnya `http://localhost:3000/api/files/...` sementara ERP menyimpan
     * `http://127.0.0.1:3000`. Pencocokan awalan teks menolaknya, kunci tak
     * ikut, dan SEMUA lampiran cermin gagal "HTTP 401" yang di layar terbaca
     * seperti berkasnya hilang.
     */
    public function test_kunci_waha_ikut_walau_host_loopback_ditulis_berbeda(): void
    {
        Storage::fake('local');

        $this->kirim($this->payload(pesan: [
            'hasMedia' => true,
            'media'    => [
                'url'      => 'http://localhost:3000/api/files/utama/abc.jpeg',
                'mimetype' => 'image/jpeg',
                'filename' => 'desain.jpeg',
            ],
        ]))->assertOk();

        Http::fake(['localhost:3000/*' => Http::response('gambar-palsu', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->artisan('crm:unduh-lampiran')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'kunci-waha'));

        $lampiran = CrmAttachment::sole()->fresh();

        $this->assertNotNull($lampiran->downloaded_at);
        $this->assertNull($lampiran->download_error);
    }

    /**
     * Kunci itu setara akses penuh akun WhatsApp, jadi ia tidak pernah ikut ke
     * alamat selain WAHA kita sendiri — termasuk host lain, port lain, dan
     * URL media milik Meta.
     *
     * @dataProvider alamatBukanWaha
     */
    public function test_kunci_waha_tidak_pernah_bocor_ke_alamat_lain(string $url, string $pola): void
    {
        Storage::fake('local');

        $this->kirim($this->payload(pesan: [
            'hasMedia' => true,
            'media'    => ['url' => $url, 'mimetype' => 'image/jpeg', 'filename' => 'desain.jpeg'],
        ]))->assertOk();

        Http::fake([$pola => Http::response('gambar-palsu', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->artisan('crm:unduh-lampiran')->assertSuccessful();

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Api-Key'));
    }

    public static function alamatBukanWaha(): array
    {
        return [
            'host lain'      => ['http://waha.contoh.test:3000/api/files/abc.jpeg', 'waha.contoh.test:3000/*'],
            'port lain'      => ['http://127.0.0.1:9999/api/files/abc.jpeg', '127.0.0.1:9999/*'],
            'media vendor'   => ['https://lookaside.fbcdn.net/abc.jpeg', 'lookaside.fbcdn.net/*'],
        ];
    }

    /* ------------------------------------------------------------- centang */

    /** Pesan kita dari HP langsung membawa centangnya sendiri di `message.any`. */
    public function test_centang_terisi_dari_ack_saat_merekam(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'      => 'true_628123456789@c.us_3EBKELUAR',
            'fromMe'  => true,
            'from'    => '628998844666@c.us',
            'to'      => '628123456789@c.us',
            'body'    => 'Ada kak.',
            'ack'     => 2,
            'ackName' => 'DEVICE',
        ]))->assertOk();

        $pesan = CrmMessage::sole();

        $this->assertSame('delivered', $pesan->status);
        $this->assertSame('sampai', $pesan->centang());
    }

    /**
     * Pesan MASUK tidak pernah diberi status.
     *
     * `ack` pada pesan masuk adalah tanda baca KITA, bukan kabar tentang
     * pelanggan — dan `centang()` memang mengabaikan pesan masuk. Menulisnya
     * hanya menaruh data yang tak seorang pun bisa menafsirkan.
     */
    public function test_ack_pesan_masuk_tidak_menulis_status(): void
    {
        $this->kirim($this->payload(pesan: ['ack' => 3, 'ackName' => 'READ']))->assertOk();

        $this->assertNull(CrmMessage::sole()->status);
    }

    /** Peristiwa `message.ack` menaikkan centang pesan yang sudah direkam. */
    public function test_peristiwa_ack_menaikkan_centang(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'      => 'true_628123456789@c.us_3EBKELUAR',
            'fromMe'  => true,
            'from'    => '628998844666@c.us',
            'to'      => '628123456789@c.us',
            'ack'     => 1,
            'ackName' => 'SERVER',
        ]))->assertOk();

        $this->assertSame('terkirim', CrmMessage::sole()->status);

        $this->kirim($this->ack('true_628123456789@c.us_3EBKELUAR', 3, 'READ'))->assertOk();

        $this->assertSame('read', CrmMessage::sole()->fresh()->status);
        $this->assertSame('dibaca', CrmMessage::sole()->fresh()->centang());
        $this->assertSame(1, CrmMessage::count(), 'ack tidak boleh melahirkan baris pesan');
    }

    /**
     * Ack yang datang terlambat TIDAK menurunkan centang.
     *
     * WAHA tidak menjamin urutan webhook. Ditulis apa adanya, centang biru
     * akan berubah kembali jadi abu-abu — di layar itu terbaca seperti
     * pelanggan membatalkan bacaannya, kejadian yang tidak ada di WhatsApp.
     */
    public function test_ack_terlambat_tidak_menurunkan_centang(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'      => 'true_628123456789@c.us_3EBKELUAR',
            'fromMe'  => true,
            'from'    => '628998844666@c.us',
            'to'      => '628123456789@c.us',
            'ack'     => 3,
            'ackName' => 'READ',
        ]))->assertOk();

        $this->kirim($this->ack('true_628123456789@c.us_3EBKELUAR', 2, 'DEVICE'))->assertOk();

        $this->assertSame('read', CrmMessage::sole()->fresh()->status);
    }

    /** Ack atas pesan yang tak pernah direkam (mis. grup) didiamkan. */
    public function test_ack_pesan_asing_tidak_menimbulkan_galat(): void
    {
        $this->kirim($this->ack('true_62800@g.us_3EBASING', 3, 'READ'))->assertOk();

        $this->assertSame(0, CrmMessage::count());
    }

    /** Perintah backfill mengisi centang dari `raw`, tanpa memanggil WAHA. */
    public function test_perintah_mengisi_centang_dari_raw(): void
    {
        $this->kirim($this->payload(pesan: [
            'id'      => 'true_628123456789@c.us_3EBKELUAR',
            'fromMe'  => true,
            'from'    => '628998844666@c.us',
            'to'      => '628123456789@c.us',
            'ack'     => 3,
            'ackName' => 'READ',
        ]))->assertOk();

        // Keadaan sebelum perbaikan ini: direkam tanpa status.
        CrmMessage::sole()->forceFill(['status' => null])->save();

        $this->artisan('crm:isi-centang-cermin', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull(CrmMessage::sole()->fresh()->status, 'uji coba tidak boleh menulis');

        $this->artisan('crm:isi-centang-cermin')->assertSuccessful();
        $this->assertSame('read', CrmMessage::sole()->fresh()->status);
    }

    /**
     * Langganan `message.ack` ikut dipasang tombol "Pasang Cermin".
     *
     * Tanpa itu centang membeku di keadaan saat pesan direkam: "sampai" tidak
     * pernah berubah jadi "dibaca", dan tak ada galat apa pun yang menandainya.
     */
    public function test_pasang_cermin_melanggan_peristiwa_ack(): void
    {
        Http::fake([
            '127.0.0.1:3000/api/sessions/utama' => Http::sequence()
                ->push(['name' => 'utama', 'config' => ['webhooks' => []]], 200)
                ->push(['name' => 'utama'], 200),
        ]);

        $hasil = (new \App\Modules\CRM\Providers\WahaProvider(
            CrmSetting::for('waha'),
            \App\Modules\CRM\Support\PeranWaha::UTAMA
        ))->pasangWebhook('https://erp.contoh.test/crm/waha/webhook/' . self::TOKEN);

        $this->assertTrue($hasil['success']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $events = data_get($request->data(), 'config.webhooks.0.events', []);

            return in_array('message.any', $events, true)
                && in_array('message.ack', $events, true);
        });
    }

    /** Amplop `message.ack` apa adanya dari WAHA. */
    private function ack(string $idPesan, int $ack, string $nama): array
    {
        return [
            'id'        => 'evt_' . uniqid(),
            'timestamp' => 1758700000000,
            'event'     => 'message.ack',
            'session'   => 'utama',
            'me'        => ['id' => '628998844666@c.us'],
            'engine'    => 'NOWEB',
            'payload'   => [
                'id'      => $idPesan,
                'from'    => '628123456789@c.us',
                'fromMe'  => true,
                'ack'     => $ack,
                'ackName' => $nama,
            ],
        ];
    }

    /* -------------------------------------------------------- belum dibaca */

    /** Pesan masuk LIVE menyalakan lencana belum-dibaca. */
    public function test_pesan_masuk_live_menaikkan_belum_dibaca(): void
    {
        $this->kirim($this->payload())->assertOk();

        $this->assertSame(1, CrmConversation::sole()->unread_count);

        $this->kirim($this->payload(pesan: ['id' => 'false_628123456789@c.us_3EBDUA']))->assertOk();

        $this->assertSame(2, CrmConversation::sole()->fresh()->unread_count);
    }

    /**
     * Balasan CS dari HP MENGOSONGKAN lencananya.
     *
     * Pekerjaannya memang sudah dikerjakan, cuma tidak di layar ini. Lencana
     * yang tetap menyala sesudah dijawab adalah cara tercepat membuat orang
     * berhenti mempercayainya.
     */
    public function test_balasan_dari_hp_mengosongkan_belum_dibaca(): void
    {
        $this->kirim($this->payload())->assertOk();

        $this->kirim($this->payload(pesan: [
            'id'     => 'true_628123456789@c.us_3EBBALAS',
            'fromMe' => true,
            'from'   => '628998844666@c.us',
            'to'     => '628123456789@c.us',
            'body'   => 'Ada kak.',
        ]))->assertOk();

        $this->assertSame(0, CrmConversation::sole()->fresh()->unread_count);
    }

    /**
     * Impor riwayat TIDAK menyalakan lencana.
     *
     * Ia menyusuri ribuan pesan lama lewat jalur rekam() yang sama; tanpa
     * pemisah ini, sekali impor menandai ratusan thread sebagai pekerjaan baru
     * yang sebenarnya sudah dijawab berbulan-bulan lalu.
     */
    public function test_impor_riwayat_tidak_menaikkan_belum_dibaca(): void
    {
        $payload = $this->payload()['payload'];

        app(\App\Modules\CRM\Services\WahaCerminService::class)
            ->rekam($payload, '628123456789@c.us');

        $this->assertSame(1, CrmMessage::count());
        $this->assertSame(0, CrmConversation::sole()->unread_count);
    }
}
