<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Shipping\Services\ShipmentBookingService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "Proses Pesanan" menerbitkan resi sekalian.
 *
 * Dulu prosesnya berhenti di faktur + Surat Jalan, lalu operator harus menekan "Generate
 * Resi" sebagai langkah kedua — dan langkah itu menanyakan ULANG berat & dimensi yang sudah
 * dikunci di sub-tab "Perlu Ukur". Pesanan pun menumpuk di "Belum di-generate" padahal tidak
 * ada keputusan tersisa yang perlu diambil manusia.
 */
class FulfillmentAutoResiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** SO lunas, sudah diukur, kurir API — siap diproses. */
    private function so(array $attrs = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);

        $so = SalesOrder::create(array_merge([
            'order_number'          => 'SO-AR-' . uniqid(),
            'customer_id'           => $cust->id,
            'warehouse_id'          => $wh->id,
            'order_date'            => now()->toDateString(),
            'global_discount_type'  => 'nominal',
            'status'                => 'confirmed',
            'delivery_method'       => 'kurir',
            'shipping_provider'     => 'jubelio_shipment',
            'shipping_courier_code' => '12',
            'shipping_service_code' => '34',
            'grand_total'           => 100000,
            'paid_amount'           => 100000,
            'measured_at'           => now(),
            'package_weight_gram'   => 3200,
        ], $attrs));

        $product = Product::create([
            'sku' => 'RDY-' . uniqid(), 'name' => 'Frame Akrilik',
            'sale_type' => 'ready', 'weight_gram' => 800,
            'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 5,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'warehouse_id' => $so->warehouse_id, 'qty_on_hand' => 10,
        ]);

        // Lapisan FIFO — tanpa ini HPP tak bisa dihitung & faktur ditolak.
        \App\Core\Inventory\StockLayer::create([
            'product_id'    => $product->id,
            'warehouse_id'  => $so->warehouse_id,
            'qty_in'        => 10,
            'qty_remaining' => 10,
            'unit_cost'     => 20000,
            'source_type'   => 'opening',
            'source_id'     => 0,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'qty'                => 2,
            'conversion_to_base' => 1,
            'unit_price'         => 50000,
            'net_unit_price'     => 50000,
            'line_subtotal'      => 100000,
            'line_discount'      => 0,
            'line_total'         => 100000,
        ]);

        return $so->refresh();
    }

    /** Booking dipalsukan — tesnya soal alur, bukan soal API kurir. */
    private function bookingPalsu(array $hasil, int $kali = 1): void
    {
        $this->mock(ShipmentBookingService::class, function (MockInterface $m) use ($hasil, $kali) {
            $m->shouldReceive('book')->times($kali)->andReturn($hasil);
        });
    }

    private function suksesBook(string $resi = 'JX1234567890'): array
    {
        return ['success' => true, 'level' => 'success', 'tracking' => $resi, 'message' => "resi {$resi}"];
    }

    public function test_proses_pesanan_sekalian_menerbitkan_resi_dan_mendarat_di_belum_dicetak(): void
    {
        $so = $this->so();
        $this->bookingPalsu($this->suksesBook());

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.proses', $so->id))
            ->assertRedirect(route('pos.fulfillment.telah-diproses', ['resi' => 'belum_cetak']))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'JX1234567890'));
    }

    /** Faktur & Surat Jalan sudah terlanjur jadi — booking gagal tidak boleh membatalkannya. */
    public function test_booking_gagal_tidak_menggagalkan_proses(): void
    {
        $so = $this->so();
        $this->bookingPalsu([
            'success' => false, 'level' => 'error', 'tracking' => null,
            'message' => 'SJ-1: kode pos penerima kosong',
        ]);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.proses', $so->id))
            ->assertRedirect(route('pos.fulfillment.telah-diproses', ['resi' => 'belum_generate']))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'kode pos penerima kosong'));

        $this->assertDatabaseHas('sales_invoices', ['sales_order_id' => $so->id, 'status' => 'posted']);
    }

    /** Resi terbit tapi jurnalnya gagal: resinya nyata, jadi tetap dihitung berhasil. */
    public function test_resi_terbit_dengan_jurnal_gagal_tetap_dihitung_terbit(): void
    {
        $so = $this->so();
        $this->bookingPalsu([
            'success' => true, 'level' => 'warning', 'tracking' => 'JX999',
            'message' => 'SJ-1: resi terbuat (JX999) tetapi jurnal ongkir gagal',
        ]);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.proses', $so->id))
            // Ada yang perlu ditindak (jurnal), jadi diarahkan ke Belum di-generate + warning.
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'JX999'));
    }

    public function test_ambil_di_toko_tidak_membooking_kurir(): void
    {
        $so = $this->so([
            'delivery_method'       => 'ambil_toko',
            'pickup_code'           => '1234',
            'shipping_provider'     => null,
            'shipping_courier_code' => null,
            'shipping_service_code' => null,
        ]);
        $this->bookingPalsu($this->suksesBook(), kali: 0);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.proses', $so->id), ['pickup_code' => '1234'])
            ->assertRedirect(route('pos.fulfillment.telah-diproses'));
    }

    public function test_kurir_manual_tidak_membooking_kurir(): void
    {
        $manual = \App\Models\ManualCourier::create([
            'name' => 'Kurir Toko', 'code' => 'kurir_toko', 'is_active' => true,
        ]);

        $so = $this->so([
            'shipping_provider'     => null,
            'shipping_courier_code' => $manual->code,
            'shipping_service_code' => null,
        ]);
        $this->bookingPalsu($this->suksesBook(), kali: 0);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.proses', $so->id))
            ->assertRedirect(route('pos.fulfillment.telah-diproses'));
    }

    public function test_proses_massal_juga_menerbitkan_resi(): void
    {
        $a = $this->so();
        $b = $this->so();
        $this->bookingPalsu($this->suksesBook(), kali: 2);

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.proses-bulk'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect(route('pos.fulfillment.telah-diproses', ['resi' => 'belum_cetak']));
    }
}
