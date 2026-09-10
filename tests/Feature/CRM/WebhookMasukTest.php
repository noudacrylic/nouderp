<?php

namespace Tests\Feature\CRM;

use App\Models\Customer;
use App\Models\CrmSetting;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Models\CrmWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tahap 4 modul CRM: penerimaan webhook.
 *
 * Tiga hal yang paling mahal kalau salah, dan ketiganya diuji di sini:
 * tanda tangan yang dihitung atas badan yang sudah diolah (semua webhook sah
 * ditolak), kiriman ulang yang melahirkan pesan kembar, dan lampiran yang
 * telat diunduh sampai Meta membuangnya.
 */
class WebhookMasukTest extends TestCase
{
    use RefreshDatabase;

    private const RAHASIA = 'rahasia-webhook-uji';

    protected function setUp(): void
    {
        parent::setUp();

        CrmSetting::for('apicoid')->forceFill(['webhook_secret' => self::RAHASIA])->save();

        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /* ------------------------------------------------------------ tanda tangan */

    public function test_tanda_tangan_dihitung_atas_badan_mentah_bukan_json_yang_disusun_ulang(): void
    {
        // Spasi & urutan kunci sengaja tidak rapi. Kalau middleware menyusun
        // ulang JSON sebelum menghitung HMAC, tanda tangan yang SAH ini akan
        // ditolak — persis kegagalan yang paling sulit dilacak di produksi.
        $raw = '{"event_type":"message.received",  "event_id":"11111111-1111-1111-1111-111111111111",'
             . '"data":{"phone_number":"628998844666","message_id":"m-mentah","content":"halo"}}';

        $this->kirimMentah($raw, 'k-mentah')->assertOk();

        $this->assertSame(1, CrmMessage::count());
        $this->assertSame('halo', CrmMessage::first()->content);
    }

    public function test_badan_yang_diubah_setelah_ditandatangani_ditolak(): void
    {
        $asli   = json_encode($this->payloadMasuk('m-1', 'halo'));
        $diubah = json_encode($this->payloadMasuk('m-1', 'transfer ke rekening lain'));

        $tanda = hash_hmac('sha256', $asli, self::RAHASIA);

        $this->call('POST', '/crm/webhook', [], [], [], [
            'CONTENT_TYPE'                   => 'application/json',
            'HTTP_ACCEPT'                    => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE'       => $tanda,
            'HTTP_X_WEBHOOK_IDEMPOTENCY_KEY' => 'k-diubah',
        ], $diubah)->assertForbidden();

        $this->assertSame(0, CrmMessage::count());
        $this->assertSame(0, CrmWebhookEvent::count());
    }

    public function test_permintaan_tanpa_tanda_tangan_ditolak(): void
    {
        $this->postJson('/crm/webhook', $this->payloadMasuk('m-2'))->assertUnauthorized();

        $this->assertSame(0, CrmMessage::count());
    }

    public function test_rahasia_belum_diisi_menolak_semua_kiriman(): void
    {
        CrmSetting::for('apicoid')->forceFill(['webhook_secret' => null])->save();

        $this->kirim($this->payloadMasuk('m-3'), 'k-3')->assertStatus(503);

        $this->assertSame(0, CrmMessage::count());
    }

    /**
     * Rute bertoken WAJIB dikecualikan dari CSRF.
     *
     * Tidak bisa dibuktikan lewat request tes (Laravel mematikan VerifyCsrfToken
     * saat testing), jadi yang diperiksa daftarnya langsung. Ini menutup bug
     * nyata: `crm/webhook` sudah dikecualikan, `crm/webhook/*` terlewat, dan
     * vendor menerima **419** — status yang tak menyebut CSRF sama sekali,
     * jadi terbaca seperti "endpointnya rusak".
     */
    public function test_rute_webhook_bertoken_dikecualikan_dari_csrf(): void
    {
        $ref = new \ReflectionClass(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        $prop = $ref->getProperty('neverVerify');
        $prop->setAccessible(true);

        $daftar = (array) $prop->getValue();

        $this->assertContains('crm/webhook', $daftar);
        $this->assertContains('crm/webhook/*', $daftar);
    }

    /* ------------------------------------------------- payload vendor sungguhan */

    /**
     * Putar ulang kiriman NYATA dari api.co.id (direkam 5 Sep 2026 lewat ngrok).
     *
     * Ini yang menangkap perbedaan mahal antara dokumentasi dan kenyataan:
     * dokumen menyebut `phone_number`, kiriman aslinya memakai `customer_phone`.
     * Tanpa tes ini, gejalanya bukan error melainkan percakapan yang lahir
     * tanpa pemilik — jenis kegagalan yang baru ketahuan berminggu-minggu
     * kemudian, saat inbox penuh thread anonim.
     */
    public function test_payload_asli_vendor_terbaca_utuh(): void
    {
        $raw = file_get_contents(base_path('tests/Fixtures/crm/apicoid-message-received.json'));

        $this->kirimMentah($raw, 'k-asli')->assertOk();

        $pesan = CrmMessage::firstOrFail();

        $this->assertSame('Selamat pagi', $pesan->content);
        $this->assertSame('cmtnl12d189qkcuru11lpkily', $pesan->provider_message_id);
        $this->assertStringStartsWith('wamid.', (string) $pesan->wam_id);
        $this->assertSame(CrmMessage::MASUK, $pesan->direction);

        $percakapan = $pesan->conversation;

        $this->assertSame('628998844666', $percakapan->contact_key);
        $this->assertSame('cmtnigb1o859kcuruirdp10dh', $percakapan->business_number_id);
        $this->assertSame(CrmConversation::QUEUE_KITA, $percakapan->queue_state);
        $this->assertNotNull($percakapan->window_expires_at);
    }

    /**
     * Vendor mengirim UTC ('...Z'); ERP hidup di Asia/Jakarta.
     *
     * Tanpa konversi, jam UTC ditulis apa adanya ke kolom yang dibaca sebagai
     * waktu lokal — seluruh thread meleset 7 jam dan pesan pagi tampil sebagai
     * pesan tengah malam kemarin. Ketahuan dari payload asli: dasbor vendor
     * menulis 06:25, ERP menampilkan 23:25 hari sebelumnya.
     */
    public function test_waktu_vendor_dikonversi_ke_zona_aplikasi(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);

        $payload = $this->payloadMasuk('m-zona');
        $payload['timestamp'] = '2026-09-04T23:25:41.567Z';

        $this->kirim($payload, 'k-zona')->assertOk();

        $this->assertSame(
            '2026-09-05 06:25:41',
            CrmMessage::firstOrFail()->sent_at->format('Y-m-d H:i:s')
        );
    }

    /* --------------------------------------------------- token rahasia di path */

    /**
     * api.co.id menandatangani webhook tapi tidak memperlihatkan signing
     * secret-nya di mana pun (dibuktikan atas payload nyata 5 Sep 2026).
     * Selama itu belum ketemu, penjaganya token acak di dalam URL.
     */
    public function test_token_path_menerima_kiriman_saat_hmac_belum_ada(): void
    {
        $setting = CrmSetting::for('apicoid');
        $setting->forceFill(['webhook_secret' => null])->save();
        $token = $setting->webhookToken();

        $this->postJson("/crm/webhook/{$token}", $this->payloadMasuk('m-token'), [
            'X-Webhook-Idempotency-Key' => 'k-token',
        ])->assertOk();

        $this->assertSame(1, CrmMessage::count());
    }

    public function test_token_path_salah_ditolak(): void
    {
        $setting = CrmSetting::for('apicoid');
        $setting->forceFill(['webhook_secret' => null])->save();
        $setting->webhookToken();

        $this->postJson('/crm/webhook/token-karangan', $this->payloadMasuk('m-token-salah'), [
            'X-Webhook-Idempotency-Key' => 'k-token-salah',
        ])->assertForbidden();

        $this->assertSame(0, CrmMessage::count());
    }

    /**
     * Token path TIDAK boleh jadi jalan pintas atas HMAC. Begitu rahasia HMAC
     * terisi, dialah yang menentukan — kalau tidak, penjaga yang lebih kuat
     * bisa dilewati hanya dengan menebak URL yang bisa bocor lewat log proxy.
     */
    public function test_token_benar_tidak_melewati_hmac_yang_sudah_diisi(): void
    {
        $token = CrmSetting::for('apicoid')->webhookToken();

        $this->postJson("/crm/webhook/{$token}", $this->payloadMasuk('m-pintas'), [
            'X-Webhook-Idempotency-Key' => 'k-pintas',
        ])->assertUnauthorized();

        $this->assertSame(0, CrmMessage::count());
    }

    public function test_token_baru_mematikan_url_lama(): void
    {
        $setting = CrmSetting::for('apicoid');
        $setting->forceFill(['webhook_secret' => null])->save();
        $lama = $setting->webhookToken();

        $setting->regenerateWebhookToken();

        $this->postJson("/crm/webhook/{$lama}", $this->payloadMasuk('m-lama'), [
            'X-Webhook-Idempotency-Key' => 'k-lama',
        ])->assertForbidden();
    }

    /* ----------------------------------------------------------------- duplikat */

    public function test_kiriman_ulang_dengan_kunci_sama_tidak_melahirkan_pesan_kedua(): void
    {
        $payload = $this->payloadMasuk('m-ulang', 'halo');

        $this->kirim($payload, 'k-ulang')->assertOk();
        $this->kirim($payload, 'k-ulang')->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, CrmMessage::count());
        $this->assertSame(1, CrmWebhookEvent::count());
    }

    public function test_kunci_berbeda_tapi_pesan_sama_tetap_satu_karena_dijaga_basis_data(): void
    {
        // Vendor mengulang dengan kunci idempoten baru (mis. beda menit).
        // Penjaga terakhirnya adalah UNIQUE provider_message_id, bukan kunci header.
        $this->kirim($this->payloadMasuk('m-sama'), 'k-a')->assertOk();
        $this->kirim($this->payloadMasuk('m-sama'), 'k-b')->assertOk();

        $this->assertSame(2, CrmWebhookEvent::count());
        $this->assertSame(1, CrmMessage::count());
    }

    /* -------------------------------------------------------------- pesan masuk */

    public function test_pesan_masuk_membuat_percakapan_membuka_jendela_dan_menaruh_bola_di_kita(): void
    {
        $this->kirim($this->payloadMasuk('m-4', 'bisa custom?'), 'k-4')->assertOk();

        $percakapan = CrmConversation::first();

        $this->assertSame('628998844666', $percakapan->contact_key);
        $this->assertSame(CrmConversation::QUEUE_KITA, $percakapan->queue_state);
        $this->assertSame(1, $percakapan->unread_count);
        $this->assertTrue($percakapan->windowIsOpen());
        $this->assertNull($percakapan->customer_id, 'Kontak tak dikenal = lead, bukan galat.');
    }

    public function test_nomor_dalam_bentuk_apa_pun_jatuh_ke_percakapan_yang_sama(): void
    {
        $this->kirim($this->payloadMasuk('m-5', 'halo', '0899-8844-666'), 'k-5')->assertOk();
        $this->kirim($this->payloadMasuk('m-6', 'lanjut', '+62 899 8844 666'), 'k-6')->assertOk();

        $this->assertSame(1, CrmConversation::count());
        $this->assertSame(2, CrmMessage::count());
    }

    public function test_kontak_dicocokkan_ke_master_pelanggan_meski_nomornya_ditulis_bebas(): void
    {
        $pelanggan = Customer::create([
            'code' => 'CUST-A', 'name' => 'Budi', 'phone' => '0899-8844-666', 'is_active' => true,
        ]);

        $this->kirim($this->payloadMasuk('m-7'), 'k-7')->assertOk();

        $this->assertSame($pelanggan->id, CrmConversation::first()->customer_id);
    }

    public function test_nomor_mirip_tidak_ikut_tercocokkan(): void
    {
        Customer::create([
            'code' => 'CUST-B', 'name' => 'Bukan Budi', 'phone' => '62899884466', 'is_active' => true,
        ]);

        $this->kirim($this->payloadMasuk('m-8'), 'k-8')->assertOk();

        $this->assertNull(CrmConversation::first()->customer_id);
    }

    /* ------------------------------------------------------------- pesan keluar */

    public function test_balasan_dari_hp_tercatat_sebagai_kebocoran_dan_memindahkan_bola(): void
    {
        $this->kirim($this->payloadMasuk('m-9', 'halo'), 'k-9')->assertOk();

        $this->kirim([
            'event_type' => 'message.sent',
            'event_id'   => '22222222-2222-2222-2222-222222222222',
            'timestamp'  => now()->toIso8601String(),
            'data'       => [
                'phone_number' => '628998844666',
                'message_id'   => 'm-10',
                'content'      => 'baik kak',
                'source'       => 'WHATSAPP_APP',
            ],
        ], 'k-10')->assertOk();

        $keluar = CrmMessage::keluar()->first();
        $this->assertTrue($keluar->dibalasDariHp());

        $percakapan = CrmConversation::first();
        $this->assertSame(CrmConversation::QUEUE_PELANGGAN, $percakapan->queue_state);
        $this->assertSame(0, $percakapan->unread_count);
    }

    /* ------------------------------------------------------------ status kirim */

    public function test_status_terkirim_diperbarui_dan_kegagalan_menular_ke_outbox(): void
    {
        $this->kirim($this->payloadMasuk('m-11', 'halo'), 'k-11')->assertOk();

        $outbox = CrmOutboxMessage::antrekan('so:1:siap_diambil', [
            'event'               => CrmOutboxMessage::EVENT_SIAP_AMBIL,
            'recipient'           => '628998844666',
            'template_name'       => 'pesanan_siap_diambil',
            'status'              => CrmOutboxMessage::STATUS_TERKIRIM,
            'provider_message_id' => 'm-11',
        ]);

        $this->kirim([
            'event_type' => 'message.failed',
            'event_id'   => '33333333-3333-3333-3333-333333333333',
            'data'       => ['message_id' => 'm-11', 'error' => ['message' => 'Nomor tidak memiliki WhatsApp']],
        ], 'k-12')->assertOk();

        $this->assertSame('failed', CrmMessage::first()->status);

        $outbox->refresh();
        $this->assertSame(CrmOutboxMessage::STATUS_GAGAL, $outbox->status);
        $this->assertSame('Nomor tidak memiliki WhatsApp', $outbox->reason);
    }

    /* ---------------------------------------------------------------- lampiran */

    public function test_lampiran_dicatat_tapi_tidak_diunduh_di_dalam_permintaan_webhook(): void
    {
        // preventStrayRequests() akan menggagalkan tes bila ada HTTP di sini —
        // itulah pokoknya: vendor tidak boleh menunggu unduhan kita.
        $this->kirim($this->payloadMedia('m-13'), 'k-13')->assertOk();

        $lampiran = CrmAttachment::first();

        $this->assertNotNull($lampiran);
        $this->assertFalse($lampiran->tersimpanAman());
        $this->assertSame('https://media.example.com/logo.png', $lampiran->source_url);
    }

    /**
     * `media_url` hanya boleh dipercaya bila `media_status` = "ok".
     *
     * Vendor tetap mengirim alamat untuk media yang GAGAL ia ambil dari Meta,
     * dan alamat itu menjawab 404. Kalau disimpan apa adanya, lampirannya duduk
     * selamanya sebagai "belum terunduh" yang dicoba ulang tiap menit — dan
     * tak seorang pun tahu sebabnya, karena tak ada yang salah dari sisi kita.
     */
    public function test_media_yang_gagal_diambil_vendor_tidak_menyimpan_alamat_palsu(): void
    {
        $payload = $this->payloadMedia('m-13b');
        $payload['data']['media_status'] = 'download_failed';

        $this->kirim($payload, 'k-13b')->assertOk();

        $lampiran = CrmAttachment::firstOrFail();

        $this->assertNull($lampiran->source_url);
        $this->assertStringContainsString('Vendor gagal mengambil', (string) $lampiran->download_error);

        // Sudah punya alasan → keluar dari antrean unduh, tidak dicoba ulang.
        $this->assertSame(0, CrmAttachment::belumTerunduh()->count());
    }

    /** Pesan sekali-lihat memang tak bisa diunduh; alasannya disebut apa adanya. */
    public function test_media_sekali_lihat_dijelaskan_bukan_didiamkan(): void
    {
        $payload = $this->payloadMedia('m-13c');
        $payload['data']['media_status'] = 'unsupported';

        $this->kirim($payload, 'k-13c')->assertOk();

        $lampiran = CrmAttachment::firstOrFail();

        // Barisnya TETAP dibuat: pelanggan memang mengirim sesuatu, dan
        // gelembung yang diam-diam kosong membuat admin mengira tak ada apa-apa.
        $this->assertNotNull($lampiran);
        $this->assertStringContainsString('sekali-lihat', (string) $lampiran->download_error);
    }

    public function test_perintah_unduh_menyimpan_berkas_ke_disk_sendiri(): void
    {
        Http::fake(['media.example.com/*' => Http::response('gambar-palsu', 200, ['Content-Type' => 'image/png'])]);

        $this->kirim($this->payloadMedia('m-14'), 'k-14')->assertOk();

        $this->artisan('crm:unduh-lampiran')->assertSuccessful();

        $lampiran = CrmAttachment::first()->fresh();

        $this->assertTrue($lampiran->tersimpanAman());
        $this->assertSame(12, $lampiran->size_bytes);
        Storage::disk('local')->assertExists($lampiran->path);
    }

    public function test_berkas_melebihi_batas_tidak_ikut_tersimpan_dan_alasannya_terbaca(): void
    {
        config(['crm.max_media_bytes' => 10]);

        Http::fake(['media.example.com/*' => Http::response(str_repeat('x', 500), 200)]);

        $this->kirim($this->payloadMedia('m-15'), 'k-15')->assertOk();
        $this->artisan('crm:unduh-lampiran')->assertSuccessful();

        $lampiran = CrmAttachment::first()->fresh();

        $this->assertFalse($lampiran->tersimpanAman());
        $this->assertStringContainsString('melebihi batas', $lampiran->download_error);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    /* ------------------------------------------------------------ masa simpan */

    public function test_penyapu_membuang_lampiran_lama_yang_tidak_tertaut_apa_pun(): void
    {
        $lampiran = $this->lampiranTersimpan(umurHari: 200);

        $this->artisan('crm:gc-lampiran')->assertSuccessful();

        $lampiran->refresh();
        $this->assertTrue($lampiran->sudahDisapu());
        $this->assertNull($lampiran->path);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_lampiran_milik_pelanggan_yang_dikenali_tidak_pernah_disapu(): void
    {
        $lampiran = $this->lampiranTersimpan(umurHari: 400);

        $pelanggan = Customer::create(['code' => 'CUST-C', 'name' => 'Budi', 'is_active' => true]);
        $lampiran->message->conversation->forceFill(['customer_id' => $pelanggan->id])->save();

        $this->artisan('crm:gc-lampiran')->assertSuccessful();

        $this->assertFalse($lampiran->fresh()->sudahDisapu(), 'Di sinilah logo yang sudah tercetak berada.');
    }

    public function test_lampiran_yang_belum_lewat_masa_simpan_dibiarkan(): void
    {
        $lampiran = $this->lampiranTersimpan(umurHari: 30);

        $this->artisan('crm:gc-lampiran')->assertSuccessful();

        $this->assertFalse($lampiran->fresh()->sudahDisapu());
    }

    public function test_uji_coba_tidak_menghapus_apa_pun(): void
    {
        $lampiran = $this->lampiranTersimpan(umurHari: 200);

        $this->artisan('crm:gc-lampiran', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse($lampiran->fresh()->sudahDisapu());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_masa_simpan_lebih_pendek_dari_umur_media_meta_ditolak(): void
    {
        $this->artisan('crm:gc-lampiran', ['--days' => 7])->assertFailed();
    }

    /* ------------------------------------------------------------------ bantuan */

    private function lampiranTersimpan(int $umurHari): CrmAttachment
    {
        Http::fake(['media.example.com/*' => Http::response('gambar-palsu', 200, ['Content-Type' => 'image/png'])]);

        $this->kirim($this->payloadMedia('m-gc-' . $umurHari), 'k-gc-' . $umurHari)->assertOk();
        $this->artisan('crm:unduh-lampiran')->assertSuccessful();

        $lampiran = CrmAttachment::first();
        $lampiran->forceFill(['created_at' => now()->subDays($umurHari)])->save();

        return $lampiran;
    }

    private function payloadMasuk(string $messageId, string $isi = 'halo', string $nomor = '628998844666'): array
    {
        return [
            'event_type' => 'message.received',
            'event_id'   => (string) \Illuminate\Support\Str::uuid(),
            'timestamp'  => now()->toIso8601String(),
            'data'       => [
                'phone_number'    => $nomor,
                'phone_number_id' => 'wa-1',
                'message_id'      => $messageId,
                'message_type'    => 'text',
                'content'         => $isi,
            ],
        ];
    }

    private function payloadMedia(string $messageId): array
    {
        $payload = $this->payloadMasuk($messageId, 'ini logonya');

        $payload['data'] += [
            'message_type' => 'image',
            'media_url'    => 'https://media.example.com/logo.png',
            'media_id'     => 'media-99',
            'mime_type'    => 'image/png',
            'file_name'    => 'logo.png',
        ];

        return $payload;
    }

    private function kirim(array $payload, string $key)
    {
        return $this->kirimMentah(json_encode($payload), $key);
    }

    private function kirimMentah(string $raw, string $key)
    {
        return $this->call('POST', '/crm/webhook', [], [], [], [
            'CONTENT_TYPE'                   => 'application/json',
            'HTTP_ACCEPT'                    => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE'       => hash_hmac('sha256', $raw, self::RAHASIA),
            'HTTP_X_WEBHOOK_IDEMPOTENCY_KEY' => $key,
        ], $raw);
    }
}
