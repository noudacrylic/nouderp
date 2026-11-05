<?php

namespace Tests\Feature\Shipping;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shipping\Services\DriverKurirService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Data driver kurir instan tetap ada setelah Jubelio mengosongkannya saat paket terkirim. */
class DriverKurirTest extends TestCase
{
    use RefreshDatabase;

    private array $driver = ['name' => 'Budi Driver', 'phone' => '6281200000000'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function ($r) {
            if (str_contains($r->url(), 'jube.co')) {
                return Http::response('', 302, ['Location' => 'https://shipment.jubelio.com/s/TOKEN1']);
            }

            return Http::response(['latest_status' => 'ON_DELIVERY', 'driver_info' => $this->driver, 'history' => []]);
        });
    }

    private function sj(array $ubah = []): SalesDelivery
    {
        $cust = Customer::create(['code' => 'C-' . uniqid(), 'name' => 'Budi', 'is_active' => true]);
        $gudang = Warehouse::firstOrCreate(['name' => 'Gudang Uji']);
        $so = SalesOrder::create(['order_number' => 'SO-' . uniqid(), 'customer_id' => $cust->id, 'warehouse_id' => $gudang->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'grand_total' => 1000]);

        return SalesDelivery::create($ubah + ['delivery_number' => 'SJ-' . uniqid(), 'sales_order_id' => $so->id, 'warehouse_id' => $gudang->id,
            'delivery_method' => 'kurir', 'delivery_date' => now()->toDateString(), 'status' => 'posted', 'tracking_number' => 'IN-' . uniqid(),
            'shipping_provider' => 'jubelio_shipment', 'shipping_raw' => ['tracking_url' => 'https://jube.co/a' . uniqid()]]);
    }

    public function test_driver_tersimpan_dan_tidak_hilang_saat_jubelio_mengosongkannya(): void
    {
        $sj = $this->sj();
        $layanan = app(DriverKurirService::class);

        $this->assertTrue($layanan->tangkap($sj));
        $this->assertSame(['Budi Driver', '6281200000000'], [$sj->fresh()->driver_name, $sj->fresh()->driver_phone]);
        $this->assertFalse($layanan->tangkap($sj->fresh()), 'Data sama: tidak ditulis ulang.');

        $this->driver = ['name' => null, 'phone' => null];
        $this->assertFalse($layanan->tangkap($sj->fresh()));
        $this->assertSame('Budi Driver', $sj->fresh()->driver_name);

        $this->driver = ['name' => 'Driver Pengganti', 'phone' => '6281300000000'];
        $this->assertTrue($layanan->tangkap($sj->fresh()));
        $this->assertSame('Driver Pengganti', $sj->fresh()->driver_name);
    }

    public function test_tanpa_tautan_lacak_tidak_memanggil_jubelio(): void
    {
        $this->assertFalse(app(DriverKurirService::class)->tangkap($this->sj(['shipping_raw' => ['event' => 'x']])));
        Http::assertNothingSent();
    }

    public function test_perintah_hanya_mengecek_paket_yang_masih_di_jalan(): void
    {
        $jalan   = $this->sj();
        $sampai  = $this->sj(['delivered_at' => now()]);
        $lain    = $this->sj(['shipping_provider' => 'biteship']);

        $this->artisan('shipping:tangkap-driver')->assertSuccessful();

        $this->assertSame('Budi Driver', $jalan->fresh()->driver_name);
        $this->assertNull($sampai->fresh()->driver_name);
        $this->assertNull($lain->fresh()->driver_name);
    }

    public function test_driver_tampil_di_tampilan_surat_jalan(): void
    {
        $sj = $this->sj();
        $sj->forceFill(['driver_name' => 'Budi Driver', 'driver_phone' => '6281200000000'])->save();
        $html = view('erp.sales.deliveries._driver', ['delivery' => $sj])->render();

        $this->assertStringContainsString('Budi Driver', $html);
        $this->assertStringContainsString('6281200000000', $html);
        $this->assertSame('', trim(view('erp.sales.deliveries._driver', ['delivery' => $this->sj()])->render()));
    }
}
