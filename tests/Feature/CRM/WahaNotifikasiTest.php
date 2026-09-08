<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\NotificationManager;
use App\Modules\CRM\Providers\NotifikasiResmiProvider;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\CRM\Services\CrmOutboxSender;
use App\Modules\CRM\Support\TemplateResmi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 2 WAHA: jalur notifikasi tak resmi berdiri sejajar dengan jalur resmi.
 *
 * Yang dijaga di sini adalah hal-hal yang hanya ketahuan setelah pesan telanjur
 * sampai ke pelanggan: kalimat yang menyimpang dari template yang disetujui
 * Meta, dan antrean yang hangus diam-diam saat sesi WhatsApp sedang mati.
 */
class WahaNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => false, 'crm.allowed_recipients' => []]);

        CrmSetting::for('waha')->update([
            'is_enabled' => true,
            'api_key'    => 'kunci-uji',
            'base_url'   => 'http://127.0.0.1:3000',
            'config'     => ['session' => 'notifikasi'],
        ]);
    }

    /* ------------------------------------------------------------------- teks */

    public function test_teks_waha_sama_persis_dengan_bunyi_template_meta(): void
    {
        $teks = TemplateResmi::render('pembayaran_diterima', ['Budi', '150.000', 'SO-1', 'Lunas']);

        $this->assertSame(
            'Halo Budi, pembayaran sebesar Rp150.000 untuk pesanan SO-1 sudah kami terima. '
            . 'Status pembayaran saat ini: Lunas. Terima kasih atas kepercayaan Anda.',
            $teks
        );
    }

    /**
     * Pesan teks biasa tidak punya tombol. Kalimat "lewat tombol di bawah ini"
     * karena itu WAJIB berubah jadi URL yang benar-benar bisa diklik —
     * menyuruh pelanggan menekan tombol yang tak ada adalah cacat yang baru
     * ketahuan setelah pesannya terkirim.
     */
    public function test_kalimat_tombol_diganti_url_lacak(): void
    {
        $teks = TemplateResmi::render(
            'pesanan_dikirim',
            ['Budi', 'SO-1', 'JNE', 'JX123'],
            'https://noudakrilik.com/pesanan/tok3n'
        );

        $this->assertStringNotContainsString('tombol di bawah ini', $teks);
        $this->assertStringContainsString('https://noudakrilik.com/pesanan/tok3n', $teks);
    }

    /** Tanpa URL, janji tombolnya dibuang seluruhnya — bukan disisakan menggantung. */
    public function test_tanpa_url_kalimat_tombol_hilang_bersih(): void
    {
        $teks = TemplateResmi::render('pesanan_dikirim', ['Budi', 'SO-1', 'JNE', 'JX123'], null);

        $this->assertSame(
            'Halo Budi, pesanan SO-1 sudah kami serahkan ke JNE dengan nomor resi JX123.',
            $teks
        );
    }

    public function test_template_tak_dikenal_tidak_menghasilkan_kalimat_setengah_jadi(): void
    {
        $this->assertNull(TemplateResmi::render('template_hantu', ['Budi']));
    }

    /* ------------------------------------------------------------------ kirim */

    public function test_pengiriman_memakai_endpoint_dan_bentuk_chatid_waha(): void
    {
        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['name' => 'notifikasi', 'status' => 'WORKING']),
            '*/api/sendText'            => Http::response(['id' => 'true_628_ABC']),
        ]);

        $hasil = app(WahaProvider::class)->kirimNotifikasi([
            'to'       => '0899-8844-666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '150.000', 'SO-1', 'Lunas'],
        ]);

        $this->assertTrue($hasil['success'], (string) $hasil['error']);
        $this->assertSame('true_628_ABC', $hasil['message_id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sendText')) {
                return false;
            }

            return $request['session'] === 'notifikasi'
                && $request['chatId'] === '628998844666@c.us'
                && str_contains($request['text'], 'Halo Budi')
                && $request->header('X-Api-Key')[0] === 'kunci-uji';
        });
    }

    /**
     * Sesi mati = TERTAHAN, bukan gagal. Ini pembeda paling penting di seluruh
     * tahap ini: sesi WhatsApp putus itu lumrah dan pulihnya butuh manusia
     * menscan QR — kalau barisnya ditandai gagal, ia keluar dari antrean
     * selamanya dan pelanggan tak pernah dapat kabar meski sesi pulih semenit
     * kemudian.
     */
    public function test_sesi_belum_siap_ditahan_bukan_digagalkan(): void
    {
        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['name' => 'notifikasi', 'status' => 'SCAN_QR_CODE']),
            '*/api/sendText'            => Http::response(['id' => 'jangan-sampai-kesini']),
        ]);

        $hasil = app(WahaProvider::class)->kirimNotifikasi([
            'to'       => '628998844666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '150.000', 'SO-1', 'Lunas'],
        ]);

        $this->assertFalse($hasil['success']);
        $this->assertTrue($hasil['tahan']);
        $this->assertStringContainsString('SCAN_QR_CODE', $hasil['error']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/sendText'));
    }

    /** Container mati/restart juga sementara — antreannya harus utuh. */
    public function test_waha_tak_terjangkau_ditahan(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 503)]);

        $hasil = app(WahaProvider::class)->kirimNotifikasi([
            'to'       => '628998844666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '150.000', 'SO-1', 'Lunas'],
        ]);

        $this->assertTrue($hasil['tahan']);
    }

    /** Nomor di luar daftar putih ditolak SEBELUM menyentuh jaringan. */
    public function test_daftar_putih_menahan_nomor_asing(): void
    {
        config(['crm.allowed_recipients' => ['628111111111']]);
        Http::fake();

        $hasil = app(WahaProvider::class)->kirimNotifikasi([
            'to'       => '628998844666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '150.000', 'SO-1', 'Lunas'],
        ]);

        $this->assertFalse($hasil['success']);
        $this->assertFalse($hasil['tahan'], 'Nomor terlarang bukan keadaan sementara.');
        Http::assertNothingSent();
    }

    /* --------------------------------------------------------------- manager */

    public function test_saklar_jangan_kirim_menukar_waha_dengan_driver_palsu(): void
    {
        config(['crm.dry_run' => true, 'crm.notifikasi.driver' => 'waha']);
        Http::fake();

        $manager = app(NotificationManager::class);
        $manager->provider()->kirimNotifikasi([
            'to'       => '628998844666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '150.000', 'SO-1', 'Lunas'],
        ]);

        Http::assertNothingSent();
        $this->assertCount(1, $manager->fake()->terkirim);
        $this->assertStringContainsString('Halo Budi', $manager->fake()->terkirim[0]['teks']);
    }

    public function test_driver_bawaan_tetap_jalur_resmi(): void
    {
        $this->assertSame('resmi', app(NotificationManager::class)->provider()->key());
    }

    /** Jalur resmi memotong URL jadi isi tombol; jalur WAHA memakai URL penuh. */
    public function test_jalur_resmi_memotong_url_jadi_isi_tombol(): void
    {
        $this->assertSame('tok3n', NotifikasiResmiProvider::potonganUrl('https://noudakrilik.com/pesanan/tok3n'));
        $this->assertNull(NotifikasiResmiProvider::potonganUrl(null));
    }

    /* ---------------------------------------------------------------- outbox */

    public function test_baris_tertahan_tetap_di_antrean_dan_dijadwal_ulang(): void
    {
        config(['crm.dry_run' => true, 'crm.notifikasi.driver' => 'waha', 'crm.notifikasi.tahan_jeda_menit' => 10]);

        $manager = app(NotificationManager::class);
        $manager->fake()->tahan = true;

        $baris = CrmOutboxMessage::antrekan('uji:tahan', [
            'event'         => CrmOutboxMessage::EVENT_PEMBAYARAN,
            'recipient'     => '628998844666',
            'template_name' => 'pembayaran_diterima',
            'template_body' => ['Budi', '150.000', 'SO-1', 'Lunas'],
            'status'        => CrmOutboxMessage::STATUS_MENUNGGU,
        ]);

        $hasil = app(CrmOutboxSender::class)->kirimYangJatuhTempo();

        $this->assertSame(['terkirim' => 0, 'gagal' => 0, 'tertahan' => 1], $hasil);

        $baris->refresh();
        $this->assertSame(CrmOutboxMessage::STATUS_MENUNGGU, $baris->status, 'Yang tertahan tidak boleh keluar dari antrean.');
        $this->assertSame(1, $baris->attempts);
        $this->assertNotNull($baris->scheduled_at);
        $this->assertTrue($baris->scheduled_at->greaterThan(now()), 'Percobaan ulang harus dijadwalkan ke depan.');
        $this->assertNull($baris->sent_at);
    }
}
