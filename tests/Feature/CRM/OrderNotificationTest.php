<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\CrmOutboxSender;
use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\SDM\Models\NationalHoliday;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tahap 3 modul CRM: notifikasi keluar lengkap di atas driver palsu.
 *
 * Yang dijaga di sini adalah hal-hal yang kalau salah berujung ke pelanggan
 * sungguhan: pesan ganda, pesan ke pembeli marketplace, pesan ke orang yang
 * tidak pernah menyetujui, dan pesan tengah malam yang mengundang blokir.
 */
class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private OrderNotificationService $notifikasi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true, 'crm.store_open_hour' => 8, 'crm.store_close_hour' => 16]);

        app(ChatManager::class)->fake()->reset();

        $this->notifikasi = app(OrderNotificationService::class);
    }

    /* ---------------------------------------------------------------- isi pesan */

    public function test_dp_dan_pelunasan_menghasilkan_dua_notifikasi_dengan_status_berbeda(): void
    {
        $so = $this->salesOrder(['grand_total' => 2000000]);

        $dp = $this->notifikasi->antrekanPembayaranDiterima($so, 500000);
        $this->assertNotNull($dp);
        $this->assertSame('DP diterima, sisa 1.500.000', $dp->template_body[3]);
        $this->assertSame('500.000', $dp->template_body[1]);

        $lunas = $this->notifikasi->antrekanPembayaranDiterima($so, 2000000);
        $this->assertNotNull($lunas, 'Pelunasan adalah peristiwa baru, bukan duplikat DP.');
        $this->assertSame('Lunas', $lunas->template_body[3]);
    }

    public function test_menyimpan_ulang_dengan_nominal_sama_tidak_mengirim_pesan_kedua(): void
    {
        $so = $this->salesOrder();

        $this->assertNotNull($this->notifikasi->antrekanPembayaranDiterima($so, 100000));
        $this->assertNull($this->notifikasi->antrekanPembayaranDiterima($so, 100000));
        $this->assertSame(1, CrmOutboxMessage::count());
    }

    public function test_siap_diambil_membawa_kode_pengambilan_dan_jam_toko(): void
    {
        $so = $this->salesOrder(['pickup_code' => 'AMB-77']);

        $baris = $this->notifikasi->antrekanSiapDiambil($so);

        $this->assertSame(CrmOutboxMessage::EVENT_SIAP_AMBIL, $baris->event);
        $this->assertSame('pesanan_siap_diambil', $baris->template_name);
        $this->assertSame('AMB-77', $baris->template_body[2]);
        $this->assertSame(config('crm.store_hours_text'), $baris->template_body[3]);
    }

    public function test_pengiriman_tanpa_resi_belum_diantrekan(): void
    {
        $so = $this->salesOrder();

        $tanpaResi = $this->suratJalan($so, null);
        $this->assertNull($this->notifikasi->antrekanDikirim($so, $tanpaResi));
        $this->assertSame(0, CrmOutboxMessage::count());

        $denganResi = $this->suratJalan($so, 'JX1234567890');
        $baris = CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_DIKIRIM)->first();
        $this->assertNotNull($baris);
        $this->assertSame('JX1234567890', $baris->template_body[3]);
        $this->assertSame("sj:{$denganResi->id}:dikirim", $baris->dedupe_key);
    }

    /* ------------------------------------------------------------- empat penjaga */

    public function test_pesanan_marketplace_tidak_pernah_dikirimi_tapi_tetap_meninggalkan_jejak(): void
    {
        $so = $this->salesOrder([], ['is_marketplace' => true, 'wa_opt_in' => true, 'phone' => '628998844666']);

        $baris = $this->notifikasi->antrekanSiapDiambil($so);

        $this->assertSame(CrmOutboxMessage::STATUS_DILEWATI, $baris->status);
        $this->assertStringContainsString('marketplace', $baris->reason);
        $this->assertNull($baris->recipient);
        $this->assertSame(0, CrmOutboxMessage::jatuhTempo()->count());
    }

    public function test_pelanggan_tanpa_opt_in_dilewati(): void
    {
        $so = $this->salesOrder([], ['wa_opt_in' => false, 'phone' => '628998844666']);

        $baris = $this->notifikasi->antrekanSiapDiambil($so);

        $this->assertSame(CrmOutboxMessage::STATUS_DILEWATI, $baris->status);
        $this->assertStringContainsString('opt-in', $baris->reason);
    }

    public function test_nomor_kosong_dilewati_dengan_alasan(): void
    {
        $so = $this->salesOrder([], ['wa_opt_in' => true, 'phone' => null]);

        $baris = $this->notifikasi->antrekanSiapDiambil($so);

        $this->assertSame(CrmOutboxMessage::STATUS_DILEWATI, $baris->status);
        $this->assertStringContainsString('Nomor', $baris->reason);
    }

    public function test_nomor_penerima_dinormalkan_ke_bentuk_62(): void
    {
        $so = $this->salesOrder([], ['wa_opt_in' => true, 'phone' => '0899-8844-666']);

        $this->assertSame('628998844666', $this->notifikasi->antrekanSiapDiambil($so)->recipient);
    }

    /* ---------------------------------------------------------------- jam sopan */

    public function test_siap_diambil_di_luar_jam_buka_ditunda_ke_jam_buka_berikutnya(): void
    {
        // Rabu pukul 22.00 → Kamis pukul 08.00.
        $jadwal = $this->notifikasi->jadwalKirim(
            CrmOutboxMessage::EVENT_SIAP_AMBIL,
            Carbon::parse('2026-09-09 22:00:00')
        );

        $this->assertSame('2026-09-10 08:00:00', $jadwal->format('Y-m-d H:i:s'));
    }

    public function test_siap_diambil_melompati_minggu_dan_hari_libur_nasional(): void
    {
        NationalHoliday::create(['tanggal' => '2026-09-14', 'nama' => 'Libur Uji']);

        // Sabtu malam → Minggu dilompati, Senin libur → Selasa 08.00.
        $jadwal = $this->notifikasi->jadwalKirim(
            CrmOutboxMessage::EVENT_SIAP_AMBIL,
            Carbon::parse('2026-09-12 20:00:00')
        );

        $this->assertSame('2026-09-15 08:00:00', $jadwal->format('Y-m-d H:i:s'));
    }

    public function test_konfirmasi_pembayaran_dan_resi_tidak_pernah_ditunda(): void
    {
        $tengahMalam = Carbon::parse('2026-09-09 23:30:00');

        $this->assertNull($this->notifikasi->jadwalKirim(CrmOutboxMessage::EVENT_PEMBAYARAN, $tengahMalam));
        $this->assertNull($this->notifikasi->jadwalKirim(CrmOutboxMessage::EVENT_DIKIRIM, $tengahMalam));
    }

    /* ------------------------------------------------------------------ pemicu */

    public function test_pembayaran_bertambah_memicu_notifikasi_tapi_pengurangan_tidak(): void
    {
        $so = $this->salesOrder(['grand_total' => 1000000]);

        $so->update(['paid_amount' => 400000]);
        $this->assertSame(1, CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_PEMBAYARAN)->count());

        // Void pembayaran — bukan kabar baik, tak ada yang dikirim.
        $so->update(['paid_amount' => 0]);
        $this->assertSame(1, CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_PEMBAYARAN)->count());
    }

    public function test_pickup_status_menjadi_pending_memicu_notifikasi_siap_diambil(): void
    {
        $so = $this->salesOrder(['pickup_code' => 'AMB-01']);

        $so->update(['pickup_status' => 'pending']);
        $so->update(['pickup_status' => 'pending']);   // simpan ulang, tetap satu

        $this->assertSame(1, CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_SIAP_AMBIL)->count());
    }

    public function test_resi_terisi_memicu_notifikasi_dikirim(): void
    {
        $so = $this->salesOrder();
        $sj = $this->suratJalan($so, null);

        $sj->update(['tracking_number' => 'JX999']);

        $baris = CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_DIKIRIM)->first();
        $this->assertNotNull($baris);
        $this->assertSame("sj:{$sj->id}:dikirim", $baris->dedupe_key);
    }

    /* ---------------------------------------------------------------- pengirim */

    public function test_pengirim_hanya_memproses_yang_jatuh_tempo(): void
    {
        $so = $this->salesOrder();
        $this->notifikasi->antrekanPembayaranDiterima($so, 50000);

        // Diantrekan tapi dijadwalkan besok — belum boleh berangkat.
        CrmOutboxMessage::antrekan('nanti', [
            'event'          => CrmOutboxMessage::EVENT_SIAP_AMBIL,
            'sales_order_id' => $so->id,
            'recipient'      => '628998844666',
            'template_name'  => 'pesanan_siap_diambil',
            'template_body'  => ['a', 'b', 'c', 'd'],
            'status'         => CrmOutboxMessage::STATUS_MENUNGGU,
            'scheduled_at'   => now()->addDay(),
        ]);

        $hasil = app(CrmOutboxSender::class)->kirimYangJatuhTempo();

        $this->assertSame(['terkirim' => 1, 'gagal' => 0, 'tertahan' => 0], $hasil);
        $this->assertSame(1, CrmOutboxMessage::where('status', CrmOutboxMessage::STATUS_TERKIRIM)->count());
        $this->assertSame(1, CrmOutboxMessage::where('status', CrmOutboxMessage::STATUS_MENUNGGU)->count());
    }

    public function test_saklar_jangan_kirim_menyala_tidak_ada_yang_menyentuh_jaringan(): void
    {
        $so = $this->salesOrder(['grand_total' => 300000]);
        $this->notifikasi->antrekanPembayaranDiterima($so, 300000);

        app(CrmOutboxSender::class)->kirimYangJatuhTempo();

        $terkirim = app(ChatManager::class)->fake()->sentOfKind('template');

        $this->assertCount(1, $terkirim);
        $this->assertSame('pembayaran_diterima', $terkirim[0]['template']);
        $this->assertSame('id', $terkirim[0]['language']);
        $this->assertSame('628998844666', $terkirim[0]['to']);
        $this->assertNotEmpty($terkirim[0]['url_button_suffix'], 'Tombol lacak harus membawa token pesanan.');
    }

    public function test_kegagalan_provider_tercatat_dan_tidak_ditandai_terkirim(): void
    {
        $so    = $this->salesOrder();
        $baris = $this->notifikasi->antrekanPembayaranDiterima($so, 50000);

        app(ChatManager::class)->fake()->failWith = 'Saldo Meta habis';

        $this->assertFalse(app(CrmOutboxSender::class)->kirimSatu($baris));

        $baris->refresh();
        $this->assertSame(CrmOutboxMessage::STATUS_GAGAL, $baris->status);
        $this->assertSame('Saldo Meta habis', $baris->reason);
        $this->assertSame(1, $baris->attempts);
        $this->assertNull($baris->sent_at);
    }

    public function test_baris_dilewati_tidak_pernah_ikut_terkirim(): void
    {
        $so = $this->salesOrder([], ['is_marketplace' => true, 'wa_opt_in' => true, 'phone' => '628998844666']);
        $this->notifikasi->antrekanSiapDiambil($so);

        $hasil = app(CrmOutboxSender::class)->kirimYangJatuhTempo();

        $this->assertSame(['terkirim' => 0, 'gagal' => 0, 'tertahan' => 0], $hasil);
        $this->assertEmpty(app(ChatManager::class)->fake()->sent);
    }

    /* ------------------------------------------------------------------ bantuan */

    private function salesOrder(array $so = [], array $customer = []): SalesOrder
    {
        $cust = Customer::create($customer + [
            'code'      => 'CUST-' . uniqid(),
            'name'      => 'Budi',
            'phone'     => '628998844666',
            'wa_opt_in' => true,
            'is_active' => true,
        ]);

        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Uji CRM']);

        return SalesOrder::create($so + [
            'order_number' => 'SO-CRM-' . uniqid(),
            'customer_id'  => $cust->id,
            'warehouse_id' => $wh->id,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'grand_total'  => 100000,
        ]);
    }

    private function suratJalan(SalesOrder $so, ?string $resi): SalesDelivery
    {
        return SalesDelivery::create([
            'delivery_number' => 'SJ-CRM-' . uniqid(),
            'sales_order_id'  => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_date'   => now()->toDateString(),
            'status'          => 'draft',
            'courier_name'    => 'JNE',
            'tracking_number' => $resi,
        ]);
    }
}
