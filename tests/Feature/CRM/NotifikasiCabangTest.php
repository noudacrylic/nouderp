<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kabar UANG ke pusat, kabar BARANG ke cabang.
 *
 * Pembagian ini yang membuat cabang aman dipakai. Kalau semua kabar ikut nomor
 * cabang, tagihan purchasing mendarat di tangan orang gudang di kota lain —
 * dan tidak ada yang akan melaporkannya, karena dari sisi sistem pesannya
 * "terkirim".
 */
class NotifikasiCabangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        config(['crm.dry_run' => true]);
    }

    private function pelanggan(): Customer
    {
        return Customer::create([
            'code'      => 'CUST-' . uniqid(),
            'name'      => 'PT Sumber Jaya',
            'phone'     => '628111111111',   // purchasing pusat
            'is_active' => true,
        ]);
    }

    private function cabang(Customer $customer, ?string $nomor = '628333333333'): CustomerBranch
    {
        return $customer->branches()->create([
            'name'      => 'PT Sumber Jaya - Cabang Bandung',
            'phone'     => $nomor,
            'is_active' => true,
        ]);
    }

    private function pesanan(Customer $customer, $cabangId = null): SalesOrder
    {
        return SalesOrder::create([
            'order_number'       => 'SO-NC-' . uniqid(),
            'customer_id'        => $customer->id,
            'customer_branch_id' => $cabangId,
            'warehouse_id'       => Warehouse::firstOrCreate(['name' => 'Gudang Uji Notif Cabang'])->id,
            'order_date'         => now(),
            'status'             => 'draft',
            'grand_total'        => 500000,
            'pickup_code'        => 'AMB-1234',
        ]);
    }

    private function penerima(SalesOrder $so, string $event): array
    {
        return CrmOutboxMessage::where('sales_order_id', $so->id)
            ->where('event', $event)
            ->where('status', CrmOutboxMessage::STATUS_MENUNGGU)
            ->pluck('recipient')->sort()->values()->all();
    }

    public function test_kabar_uang_tetap_ke_nomor_pusat_walau_cabang_punya_nomor(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        app(OrderNotificationService::class)->antrekanPembayaranDiterima($so, 150000);

        $this->assertSame(
            ['628111111111'],
            $this->penerima($so, CrmOutboxMessage::EVENT_PEMBAYARAN)
        );
    }

    public function test_kabar_barang_ke_nomor_cabang(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        app(OrderNotificationService::class)->antrekanSiapDiambil($so);

        $this->assertSame(
            ['628333333333'],
            $this->penerima($so, CrmOutboxMessage::EVENT_SIAP_AMBIL)
        );
    }

    public function test_resi_dikirim_ke_nomor_cabang(): void
    {
        $customer = $this->pelanggan();
        $cabang = $this->cabang($customer);
        $so = $this->pesanan($customer, $cabang->id);

        $sj = SalesDelivery::create([
            'delivery_number' => 'DO-NC-' . uniqid(),
            'sales_order_id'  => $so->id,
            'reference_type'  => 'sales_order',
            'reference_id'    => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_date'   => now(),
            'tracking_number' => 'JX1234567890',
            'courier_name'    => 'JNE',
            'status'          => 'draft',
        ]);

        app(OrderNotificationService::class)->antrekanDikirim($so, $sj);

        $this->assertSame(
            ['628333333333'],
            $this->penerima($so, CrmOutboxMessage::EVENT_DIKIRIM)
        );
    }

    /** Cabang tanpa nomor sendiri tidak boleh membisukan kabar barang. */
    public function test_cabang_tanpa_nomor_jatuh_balik_ke_nomor_pusat(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer, null)->id);

        app(OrderNotificationService::class)->antrekanSiapDiambil($so);

        $this->assertSame(
            ['628111111111'],
            $this->penerima($so, CrmOutboxMessage::EVENT_SIAP_AMBIL)
        );
    }

    public function test_pesanan_tanpa_cabang_tidak_berubah_perilakunya(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer);

        app(OrderNotificationService::class)->antrekanSiapDiambil($so);

        $this->assertSame(
            ['628111111111'],
            $this->penerima($so, CrmOutboxMessage::EVENT_SIAP_AMBIL)
        );
    }

    /**
     * Nomor tambahan perusahaan ikut menerima kabar barang — tapi nomor pusat
     * TIDAK, karena untuk kabar barang tempatnya sudah digantikan cabang.
     */
    public function test_nomor_tambahan_ikut_menerima_kabar_barang_bersama_cabang(): void
    {
        $customer = $this->pelanggan();
        $customer->notificationPhones()->create(['label' => 'Purchasing', 'phone' => '628555555555']);
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        app(OrderNotificationService::class)->antrekanSiapDiambil($so);

        $this->assertSame(
            ['628333333333', '628555555555'],
            $this->penerima($so, CrmOutboxMessage::EVENT_SIAP_AMBIL)
        );
    }

    /* ------------------------------------------------- alamat & peta toko */

    /**
     * Pesan "siap diambil" menyebut alamat & peta.
     *
     * Pesan yang menyuruh orang datang tapi tidak menyebut ke mana memaksa
     * mereka bertanya dulu — dan di luar jam kerja pertanyaan itu tak terjawab.
     */
    public function test_pesan_siap_diambil_menyebut_alamat_dan_peta_toko(): void
    {
        config([
            'crm.store_address'  => 'Jl. Suren Raya No.25A, Semarang',
            'crm.store_maps_url' => 'https://g.co/kgs/contoh',
        ]);

        $customer = $this->pelanggan();
        $so = $this->pesanan($customer);

        app(OrderNotificationService::class)->antrekanSiapDiambil($so);

        $isi = CrmOutboxMessage::where('sales_order_id', $so->id)
            // Disaring per jenis: menyetel paid_amount memicu observer yang ikut
            // mengantrekan notifikasi PEMBAYARAN di pesanan yang sama, dan body
            // miliknya cuma empat variabel.
            ->where('event', CrmOutboxMessage::EVENT_SIAP_AMBIL)
            ->firstOrFail()->template_body;

        $this->assertSame('Jl. Suren Raya No.25A, Semarang', $isi[5]);
        $this->assertSame('https://g.co/kgs/contoh', $isi[6]);

        $teks = \App\Modules\CRM\Support\TemplateResmi::render('pesanan_siap_diambil', $isi);
        $this->assertStringContainsString('Jl. Suren Raya No.25A, Semarang', $teks);
        $this->assertStringContainsString('https://g.co/kgs/contoh', $teks);
    }

    /**
     * Slot sisa bayar tetap dikirim walau kosong.
     *
     * Kalau dilewati saat nihil, alamat menempati nomor variabel yang salah dan
     * muncul di pesan sebagai nominal pembayaran.
     */
    public function test_slot_sisa_bayar_tetap_ada_walau_lunas_agar_alamat_tidak_bergeser(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer);
        $so->forceFill(['paid_amount' => $so->grand_total])->save();

        app(OrderNotificationService::class)->antrekanSiapDiambil($so->fresh());

        $isi = CrmOutboxMessage::where('sales_order_id', $so->id)
            // Disaring per jenis: menyetel paid_amount memicu observer yang ikut
            // mengantrekan notifikasi PEMBAYARAN di pesanan yang sama, dan body
            // miliknya cuma empat variabel.
            ->where('event', CrmOutboxMessage::EVENT_SIAP_AMBIL)
            ->firstOrFail()->template_body;

        $this->assertSame('', $isi[4]);
        $this->assertNotSame('', $isi[5]);

        $teks = \App\Modules\CRM\Support\TemplateResmi::render('pesanan_siap_diambil', $isi);
        $this->assertStringNotContainsString('sisa pembayaran', $teks);
    }

    /** Jalur resmi Meta cuma punya 4 variabel — tambahan WAHA wajib dipangkas. */
    public function test_variabel_jalur_resmi_tetap_empat(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer);

        app(OrderNotificationService::class)->antrekanSiapDiambil($so);

        $isi = CrmOutboxMessage::where('sales_order_id', $so->id)
            // Disaring per jenis: menyetel paid_amount memicu observer yang ikut
            // mengantrekan notifikasi PEMBAYARAN di pesanan yang sama, dan body
            // miliknya cuma empat variabel.
            ->where('event', CrmOutboxMessage::EVENT_SIAP_AMBIL)
            ->firstOrFail()->template_body;

        $this->assertCount(4, \App\Modules\CRM\Support\TemplateResmi::variabelResmi('pesanan_siap_diambil', $isi));
    }
}
