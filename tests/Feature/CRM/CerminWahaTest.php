<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\Customer;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmWebhookEvent;
use App\Modules\CRM\Services\CrmReplyService;
use App\Modules\CRM\Services\WebhookHealthService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
     * INTI Tahap 6. Cermin tidak menaikkan unread, tidak memindahkan antrean,
     * dan yang paling menentukan: tidak membuka jendela 24 jam. Jendela itu
     * milik jalur berbayar; menuliskannya di sini berarti menyalakan kotak
     * ketik yang pasti ditolak saat dipakai.
     */
    public function test_cermin_tidak_menyentuh_unread_antrean_maupun_jendela(): void
    {
        $this->kirim($this->payload())->assertOk();

        $percakapan = CrmConversation::sole();

        $this->assertSame(0, $percakapan->unread_count);
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
     * Penjaga baca-saja ada di service, bukan cuma di tampilan. Kotak ketik
     * yang disembunyikan hanya menahan orang yang melihat layarnya; yang
     * ditolak di sini adalah pesannya, sebelum satu huruf pun berangkat.
     */
    public function test_thread_cermin_menolak_dibalas_dari_erp(): void
    {
        $this->kirim($this->payload())->assertOk();

        $percakapan = CrmConversation::sole();

        // Jendela sengaja dibuka supaya yang menolak BENAR-BENAR penjaga
        // cermin, bukan jendela 24 jam yang kebetulan tertutup.
        $percakapan->forceFill(['window_expires_at' => now()->addHours(5)])->save();

        $hasil = app(CrmReplyService::class)->balas($percakapan, 'coba balas dari ERP');

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('cermin baca-saja', strtolower((string) $hasil['error']));
        $this->assertSame(1, CrmMessage::count(), 'tidak ada baris keluar yang tertulis');
    }

    public function test_thread_cermin_menolak_pancingan(): void
    {
        $this->kirim($this->payload())->assertOk();

        $hasil = app(CrmReplyService::class)->kirimPancingan(CrmConversation::sole(), null, true);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('cermin baca-saja', strtolower((string) $hasil['error']));
    }
}
