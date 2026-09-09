<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\CrmSetting;
use App\Models\Customer;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\NotificationManager;
use App\Modules\CRM\Services\CrmOutboxSender;
use App\Modules\CRM\Services\DueDateReminderService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pengingat jatuh tempo pesanan tempo: H-3 lalu hari-H.
 *
 * Watak fiturnya berbeda dari penagihan tautan bayar, dan bedanya menentukan
 * segalanya: ini PENJUALAN KREDIT yang sedang berjalan normal. Pembelinya
 * tidak menghilang, ia cuma belum sampai tanggalnya. Karena itu yang dijaga di
 * sini adalah pengingatnya cukup dua kali, tepat waktu, dan lewat jalur yang
 * bisa dipercaya — BUKAN pembatalan apa pun.
 */
class JatuhTempoTest extends TestCase
{
    use RefreshDatabase;

    private function pelanggan(array $lain = []): Customer
    {
        return Customer::create(array_merge([
            'code'      => 'CUST-' . fake()->unique()->numberBetween(1000, 9999),
            'name'      => 'Budi',
            'phone'     => '628111111111',
            'is_active' => true,
            'wa_opt_in' => true,
        ], $lain));
    }

    private function gudang(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['name' => 'Gudang Jual'],
            ['is_active' => true, 'is_sellable' => true]
        );
    }

    /** Pesanan tempo yang jatuh tempo $selisih hari dari sekarang. */
    private function tempo(int $selisih, array $lain = [], ?Customer $c = null): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'order_number'   => 'SO-TEMPO-' . fake()->unique()->numberBetween(1000, 9999),
            'customer_id'    => ($c ?: $this->pelanggan())->id,
            'warehouse_id'   => $this->gudang()->id,
            'order_date'     => now()->toDateString(),
            'status'         => 'confirmed',
            'grand_total'    => 500000,
            'paid_amount'    => 0,
            'is_tempo'       => true,
            'tempo_due_date' => now()->addDays($selisih)->toDateString(),
        ], $lain));
    }

    private function tempoService(): DueDateReminderService
    {
        return app(DueDateReminderService::class);
    }

    private function baris(SalesOrder $so)
    {
        return CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_JATUH_TEMPO)
            ->where('sales_order_id', $so->id);
    }

    /* ---------------------------------------------------------------- jadwal */

    public function test_diingatkan_tiga_hari_sebelum_jatuh_tempo(): void
    {
        $so = $this->tempo(3);

        $this->assertSame(1, $this->tempoService()->jalankan()['diantrekan']);
        $this->assertSame('so:' . $so->id . ':tempo:h3', $this->baris($so)->first()->dedupe_key);
    }

    public function test_diingatkan_lagi_pada_hari_h(): void
    {
        $so = $this->tempo(0);

        $this->tempoService()->jalankan();

        $this->assertSame('so:' . $so->id . ':tempo:h0', $this->baris($so)->first()->dedupe_key);
    }

    /** Terlalu jauh dari jatuh tempo → belum diingatkan sama sekali. */
    public function test_belum_diingatkan_jauh_sebelum_jatuh_tempo(): void
    {
        $so = $this->tempo(10);

        $this->assertSame(0, $this->tempoService()->jalankan()['diantrekan']);
        $this->assertSame(0, $this->baris($so)->count());
    }

    /** Dua titik, dua pesan — tidak lebih, walau putarannya berkali-kali. */
    public function test_tiap_titik_hanya_sekali_seumur_pesanan(): void
    {
        $so = $this->tempo(3);

        $this->tempoService()->jalankan();
        $this->tempoService()->jalankan();
        $this->assertSame(1, $this->baris($so)->count());

        $so->forceFill(['tempo_due_date' => now()->toDateString()])->save();

        $this->tempoService()->jalankan();
        $this->tempoService()->jalankan();
        $this->assertSame(2, $this->baris($so)->count());
    }

    /**
     * Cron yang mati sehari tidak boleh menghilangkan pengingat H-3 selamanya —
     * tapi toleransinya berbatas, kalau tidak putaran pertama di server akan
     * mengirim pengingat ke tunggakan berbulan-bulan.
     */
    public function test_cron_yang_telat_sehari_masih_mengirim_h3(): void
    {
        $so = $this->tempo(2);   // H-3 terlewat sehari

        $this->assertSame(1, $this->tempoService()->jalankan()['diantrekan']);
        $this->assertSame('so:' . $so->id . ':tempo:h3', $this->baris($so)->first()->dedupe_key);
    }

    public function test_tunggakan_lama_tidak_disapu(): void
    {
        $so = $this->tempo(-45);

        $this->assertSame(0, $this->tempoService()->jalankan()['diantrekan']);
        $this->assertSame(0, $this->baris($so)->count());
    }

    /* ----------------------------------------------------------- yang dilewati */

    public function test_pesanan_lunas_tidak_diingatkan(): void
    {
        $so = $this->tempo(3, ['paid_amount' => 500000]);

        $this->assertSame(0, $this->tempoService()->jalankan()['diantrekan']);
        $this->assertSame(0, $this->baris($so)->count());
    }

    /**
     * DP sudah masuk tapi sisanya belum — kasus tempo yang paling lazim.
     * Pengingatnya tetap perlu, dan angka yang disebut adalah SISANYA.
     */
    public function test_sebagian_lunas_tetap_diingatkan_dengan_angka_sisa(): void
    {
        $so = $this->tempo(3, ['paid_amount' => 200000]);

        $this->tempoService()->jalankan();

        $isi = $this->baris($so)->firstOrFail()->template_body;

        $this->assertSame('Budi', $isi[0]);
        $this->assertSame($so->order_number, $isi[1]);
        $this->assertSame('300.000', $isi[2]);
        $this->assertSame(now()->addDays(3)->translatedFormat('j F Y'), $isi[3]);
    }

    public function test_pesanan_yang_bukan_tempo_tidak_diingatkan(): void
    {
        $so = $this->tempo(3, ['is_tempo' => false, 'tempo_due_date' => null]);

        $this->assertSame(0, $this->tempoService()->jalankan()['diantrekan']);
    }

    public function test_pelanggan_marketplace_tidak_diingatkan(): void
    {
        $so = $this->tempo(0, [], $this->pelanggan(['is_marketplace' => true]));

        $this->assertSame(0, $this->baris($so)->count());
        $this->assertSame(0, $this->tempoService()->jalankan()['diantrekan']);
    }

    /* -------------------------------------------------------------- jalurnya */

    /**
     * Inti pembeda jenis ini: JALUR RESMI, walau seluruh sistem sedang memakai
     * WAHA. Pesan soal utang yang jatuh tempo dari nomor tanpa centang
     * menempatkan pelanggan pada pilihan yang sama-sama buruk — mengabaikan
     * tagihan yang sah, atau memercayai pesan yang tak bisa ia verifikasi.
     */
    public function test_selalu_lewat_jalur_resmi_walau_driver_waha(): void
    {
        config([
            'crm.notifikasi.driver'   => 'waha',
            'crm.dry_run'             => false,
            'crm.allowed_recipients'  => [],
        ]);

        CrmSetting::for('waha')->update([
            'is_enabled' => true,
            'api_key'    => 'kunci-uji',
            'base_url'   => 'http://127.0.0.1:3000',
            'config'     => ['session' => 'notifikasi'],
        ]);

        // Semua HTTP dijaring. Kalau routingnya salah, jejaknya terlihat di
        // sini sebagai permintaan ke endpoint WAHA.
        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['name' => 'notifikasi', 'status' => 'WORKING']),
            '*/api/sendText'            => Http::response(['id' => 'jangan-sampai-kesini']),
            '*'                         => Http::response(['messages' => [['id' => 'wamid.RESMI']]]),
        ]);

        $this->assertTrue(app(NotificationManager::class)->pakaiWaha(), 'Prasyarat: driver memang sedang WAHA.');

        $so = $this->tempo(0);
        $this->tempoService()->jalankan();

        app(CrmOutboxSender::class)->kirimSatu($this->baris($so)->firstOrFail());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/sendText'));
    }

    public function test_perintah_terjadwal_mengantrekan(): void
    {
        $so = $this->tempo(3);

        $this->artisan('crm:ingatkan-jatuh-tempo')->assertSuccessful();

        $this->assertSame(1, $this->baris($so)->count());
    }
}
