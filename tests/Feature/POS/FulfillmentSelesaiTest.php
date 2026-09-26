<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tab "Selesai" menampilkan SELURUH pesanan yang sudah tuntas.
 *
 * Dulu ia lewat mesin bucket yang menghidrasi semua baris ke memori, dan supaya
 * muat, apa pun yang selesai lebih dari tiga hari lalu diarsipkan. Akibatnya tab
 * ini nyaris selalu kosong padahal transaksinya sudah ribuan. Sekarang ia
 * dipaginasi di SQL dan tidak mengarsip apa pun.
 *
 * Empat jalan sebuah pesanan dinyatakan selesai, dan keempatnya dijaga di sini.
 */
class FulfillmentSelesaiTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function pelanggan(bool $marketplace = false): Customer
    {
        return Customer::create([
            'code'           => 'CUST-' . uniqid(),
            'name'           => $marketplace ? 'Shopee Official' : 'Toko Budi',
            'is_marketplace' => $marketplace,
            'is_active'      => true,
        ]);
    }

    /** SO + faktur posted, ditanggali LAMA supaya arsip 3 hari yang dulu pasti menjatuhkannya. */
    private function pesanan(array $attrs = [], bool $marketplace = false): SalesOrder
    {
        $so = SalesOrder::create(array_merge([
            'order_number'         => 'SO-' . uniqid(),
            'customer_id'          => $this->pelanggan($marketplace)->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => now()->subDays(60)->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'subtotal'             => 100000,
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'delivery_method'      => 'kurir',
        ], $attrs));

        SalesInvoice::create([
            'invoice_number'  => 'INV-' . uniqid(),
            'sales_order_id'  => $so->id,
            'customer_id'     => $so->customer_id,
            'warehouse_id'    => $so->warehouse_id,
            'invoice_date'    => $so->order_date,
            'delivery_method' => $so->delivery_method,
            'status'          => 'posted',
            'subtotal'        => 100000,
            'grand_total'     => 100000,
        ]);

        return $so;
    }

    private function nomorDiTabSelesai(array $filters = [], ?string $q = null): array
    {
        return app(FulfillmentReadinessService::class)
            ->selesaiPaginated($q, $filters, 50)
            ->getCollection()
            ->pluck('number')
            ->all();
    }

    /* ------------------------------------------------- 1. marketplace */

    public function test_marketplace_selesai_walau_sudah_lama_berlalu(): void
    {
        $so = $this->pesanan([], marketplace: true);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 5001,
            'jubelio_salesorder_no' => 'TP-5001',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'completed',
            'mp_completed_at'       => now()->subDays(45),
        ]);

        // Inilah yang dulu hilang: selesai 45 hari lalu, jauh di luar arsip 3 hari.
        $this->assertContains($so->order_number, $this->nomorDiTabSelesai());
    }

    public function test_marketplace_belum_ditandai_selesai_tidak_ikut(): void
    {
        $so = $this->pesanan([], marketplace: true);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 5002,
            'jubelio_salesorder_no' => 'TP-5002',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'shipped',
        ]);

        $this->assertNotContains($so->order_number, $this->nomorDiTabSelesai());
    }

    /* ------------------------------------------------- 2. ambil di toko */

    public function test_ambil_toko_selesai_hanya_setelah_barangnya_diambil(): void
    {
        $belum = $this->pesanan(['delivery_method' => 'ambil_toko', 'pickup_status' => 'pending']);
        $sudah = $this->pesanan([
            'delivery_method' => 'ambil_toko',
            'pickup_status'   => 'picked_up',
            'picked_up_at'    => now()->subDays(20),
        ]);

        $tampil = $this->nomorDiTabSelesai();

        $this->assertContains($sudah->order_number, $tampil);
        // Fakturnya sudah terbit, tapi barangnya masih menunggu di rak.
        $this->assertNotContains($belum->order_number, $tampil);
    }

    /* ------------------------------------------------- 3. kasir POS */

    public function test_transaksi_kasir_tanpa_sales_order_ikut_tampil(): void
    {
        $inv = SalesInvoice::create([
            'invoice_number' => 'SI-KASIR-' . uniqid(),
            'customer_id'    => $this->pelanggan()->id,
            'warehouse_id'   => $this->warehouseId(),
            'invoice_date'   => now()->subDays(30)->toDateString(),
            'status'         => 'posted',
            'subtotal'       => 45000,
            'grand_total'    => 45000,
        ]);

        $baris = app(FulfillmentReadinessService::class)
            ->selesaiPaginated(null, [], 50)
            ->getCollection()
            ->firstWhere('number', $inv->invoice_number);

        $this->assertNotNull($baris, 'transaksi kasir harus muncul di tab Selesai');
        $this->assertSame('kasir', $baris['kind']);
    }

    /* ------------------------------------------------- 4. kurir */

    public function test_kurir_selesai_setelah_semua_paketnya_sampai(): void
    {
        $sampai = $this->pesanan();
        $jalan  = $this->pesanan();

        $this->suratJalan($sampai, now()->subDays(30));
        $this->suratJalan($jalan, null);

        $tampil = $this->nomorDiTabSelesai();

        $this->assertContains($sampai->order_number, $tampil);
        $this->assertNotContains($jalan->order_number, $tampil);
    }

    /** Kirim bertahap: satu paket masih di jalan = pesanannya belum selesai. */
    public function test_satu_paket_masih_di_jalan_membuat_pesanan_belum_selesai(): void
    {
        $so = $this->pesanan();

        $this->suratJalan($so, now()->subDays(10));
        $this->suratJalan($so, null);

        $this->assertNotContains($so->order_number, $this->nomorDiTabSelesai());
    }

    /** Dua paket yang sama-sama sampai tidak boleh melahirkan dua kartu. */
    public function test_dua_surat_jalan_tidak_menggandakan_barisnya(): void
    {
        $so = $this->pesanan();

        $this->suratJalan($so, now()->subDays(12));
        $this->suratJalan($so, now()->subDays(10));

        $nomor = $this->nomorDiTabSelesai();

        $this->assertSame([$so->order_number], array_values(array_filter(
            $nomor,
            fn ($n) => $n === $so->order_number
        )));
    }

    private function suratJalan(SalesOrder $so, $sampai): SalesDelivery
    {
        return SalesDelivery::create([
            'delivery_number' => 'SJ-' . uniqid(),
            'sales_order_id'  => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_method' => 'kurir',
            'delivery_date'   => $so->order_date,
            'status'          => 'posted',
            'delivered_at'    => $sampai,
        ]);
    }

    /* ------------------------------------------------- saringan & layar */

    public function test_saringan_channel_memisahkan_marketplace_dari_toko(): void
    {
        $mp = $this->pesanan([], marketplace: true);
        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 5003,
            'jubelio_salesorder_no' => 'TP-5003',
            'sales_order_id'        => $mp->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'completed',
            'mp_completed_at'       => now()->subDays(5),
        ]);

        $toko = $this->pesanan(['delivery_method' => 'ambil_toko', 'pickup_status' => 'picked_up', 'picked_up_at' => now()->subDays(5)]);

        $this->assertContains($mp->order_number, $this->nomorDiTabSelesai(['channel' => 'marketplace']));
        $this->assertNotContains($toko->order_number, $this->nomorDiTabSelesai(['channel' => 'marketplace']));
        $this->assertContains($toko->order_number, $this->nomorDiTabSelesai(['channel' => 'non']));
    }

    /**
     * Nomor FAKTUR ikut dicari, bukan cuma nomor SO.
     *
     * Yang dipegang orang saat menelusuri riwayat sering nomor nota — itu yang
     * tercetak, yang dikirim ke pembeli, dan yang muncul di rekening koran.
     */
    public function test_pencarian_menemukan_pesanan_lewat_nomor_faktur(): void
    {
        $so = $this->pesanan([
            'delivery_method' => 'ambil_toko',
            'pickup_status'   => 'picked_up',
            'picked_up_at'    => now()->subDays(30),
        ]);

        $faktur = SalesInvoice::where('sales_order_id', $so->id)->firstOrFail();

        $this->assertSame([$so->order_number], $this->nomorDiTabSelesai([], $faktur->invoice_number));
        $this->assertSame([$so->order_number], $this->nomorDiTabSelesai([], $so->order_number));
    }

    public function test_layar_selesai_terbuka_dan_memuat_barisnya(): void
    {
        $so = $this->pesanan(['delivery_method' => 'ambil_toko', 'pickup_status' => 'picked_up', 'picked_up_at' => now()->subDays(30)]);

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('pos.fulfillment.selesai'))
            ->assertOk()
            ->assertSee($so->order_number);
    }
}
