<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * "Cetak Resi" marketplace baru menandai pesanan sudah dicetak bila labelnya BENAR-BENAR keluar.
 *
 * Dulu tanda dipasang begitu tombol ditekan, sebelum ERP tahu apa-apa soal labelnya. Ketika
 * report Jubelio membalas "Error. An error occurred while processing your request." (HTTP 500),
 * pesanan tetap pindah ke "sudah cetak" — padahal tak selembar label pun keluar.
 *
 * Kini bila label Jubelio error, ERP mencetak label resinya sendiri (nomor resi + penerima dari
 * detail pesanan Jubelio) supaya paket tetap bisa dikirim.
 */
class CetakResiJubelioTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL_URL = 'https://report-prod.jubelio.com/?&token=abc';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function pesanan(int $jubelioId, array $link = []): JubelioOrderLink
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'order_number'         => 'SP-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
        ]);

        return JubelioOrderLink::create(array_merge([
            'jubelio_salesorder_id' => $jubelioId,
            'jubelio_salesorder_no' => 'SP-' . $jubelioId,
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'tracking_no'           => 'SPXID' . $jubelioId,
        ], $link));
    }

    private function jubelioMemberiUrl(): void
    {
        $client = Mockery::mock(JubelioClient::class);
        $client->shouldReceive('getShippingLabelUrl')
            ->andReturn(['success' => true, 'data' => ['url' => self::LABEL_URL]]);
        $client->shouldReceive('getOrder')
            ->andReturn(['success' => true, 'data' => [
                'shipping_full_name' => 'Arin',
                'shipping_phone'     => '******56',
                'shipping_address'   => 'Berjo Kulon Rt 4, KAB. SLEMAN, GODEAN, DI YOGYAKARTA, 55264',
                'courier'            => 'SPX Standard',
                'total_weight_in_kg' => '0.250',
            ]]);
        $this->app->instance(JubelioClient::class, $client);
    }

    private function reportJubelioError(): void
    {
        Http::fake(['report-prod.jubelio.com/*' => Http::response(
            '<h1>Error.</h1><h2>An error occurred while processing your request.</h2>', 500, ['Content-Type' => 'text/html']
        )]);
    }

    private function reportJubelioPdf(): void
    {
        Http::fake(['report-prod.jubelio.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf'])]);
    }

    public function test_label_error_di_jubelio_diganti_label_erp(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioError();
        $link = $this->pesanan(10444, ['shipper' => 'SPX Standard']);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi', $link->sales_order_id))
            ->assertOk()
            ->assertSee('SPXID10444')
            ->assertSee('Arin')
            ->assertSee('GODEAN')
            ->assertSee('SPX Standard')
            ->assertSee('250 g');

        $link->refresh();
        $this->assertNotNull($link->resi_printed_at, 'label ERP keluar → sudah dicetak');
        $this->assertNull($link->j_label_url, 'URL yang gagal tak boleh di-cache');
    }

    public function test_label_error_dan_resi_belum_terbit_tidak_menandai_sudah_dicetak(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioError();
        $link = $this->pesanan(10446, ['tracking_no' => null]);

        $this->actingAs($this->admin())
            ->from('/erp/pos/fulfillment')
            ->get(route('pos.fulfillment.jubelio-resi', $link->sales_order_id))
            ->assertRedirect('/erp/pos/fulfillment')
            ->assertSessionHas('error');

        $this->assertNull($link->fresh()->resi_printed_at);
    }

    public function test_label_berhasil_ditampilkan_lalu_ditandai_sudah_dicetak(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioPdf();
        $link = $this->pesanan(10445);

        $res = $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi', $link->sales_order_id));

        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));

        $link->refresh();
        $this->assertNotNull($link->resi_printed_at);
        $this->assertSame(self::LABEL_URL, $link->j_label_url);
    }

    public function test_cetak_massal_error_diganti_label_erp(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioError();
        $a = $this->pesanan(10432);
        $b = $this->pesanan(10433);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi-bulk', ['so' => $a->sales_order_id . ',' . $b->sales_order_id]))
            ->assertOk()
            ->assertSee('SPXID10432')
            ->assertSee('SPXID10433');

        $this->assertNotNull($a->fresh()->resi_printed_at);
        $this->assertNotNull($b->fresh()->resi_printed_at);
    }

    private function reportJubelioPenampil(): void
    {
        Http::fake(['report-prod.jubelio.com/*' => Http::response('<div id="reportViewer"></div>', 200, ['Content-Type' => 'text/html'])]);
    }

    /**
     * Penampil report Jubelio langsung ditandai sudah dicetak (packing tak pernah menekan tombol
     * konfirmasi). Ia dibingkai bersama tombol "Cetak Label ERP" untuk saat penampilnya macet.
     */
    public function test_penampil_jubelio_dibingkai_dan_langsung_ditandai(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioPenampil();
        $link = $this->pesanan(10451);

        $this->actingAs($this->admin())
            ->from('/erp/pos/fulfillment/telah-diproses')
            ->get(route('pos.fulfillment.jubelio-resi', $link->sales_order_id))
            ->assertOk()
            ->assertSee(self::LABEL_URL)
            ->assertSee('Cetak Label ERP')
            ->assertSee(url('/erp/pos/fulfillment/telah-diproses'));

        $this->assertNotNull($link->fresh()->resi_printed_at);
        $this->assertSame(self::LABEL_URL, $link->fresh()->j_label_url);
    }

    public function test_penampil_jubelio_massal_langsung_ditandai(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioPenampil();
        $a = $this->pesanan(10452);
        $b = $this->pesanan(10453);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi-bulk', ['so' => $a->sales_order_id . ',' . $b->sales_order_id]))
            ->assertOk()
            ->assertSee('Cetak Label ERP');

        $this->assertNotNull($a->fresh()->resi_printed_at);
        $this->assertNotNull($b->fresh()->resi_printed_at);
    }

    /** Tombol "Label ERP": report Jubelio bisa macet tanpa error ("0 pages loaded") — lewati sepenuhnya. */
    public function test_tombol_label_erp_tidak_menyentuh_report_jubelio(): void
    {
        $this->jubelioMemberiUrl();
        Http::fake(fn () => throw new \RuntimeException('report Jubelio tak boleh dibuka'));
        $link = $this->pesanan(10447, ['shipper' => 'SPX Hemat']);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi', ['so' => $link->sales_order_id, 'erp' => 1]))
            ->assertOk()
            ->assertSee('SPXID10447')
            ->assertSee('Arin');

        $this->assertNotNull($link->fresh()->resi_printed_at);
        $this->assertNull($link->fresh()->j_label_url);
    }

    public function test_tombol_label_erp_massal(): void
    {
        $this->jubelioMemberiUrl();
        Http::fake(fn () => throw new \RuntimeException('report Jubelio tak boleh dibuka'));
        $a = $this->pesanan(10448);
        $b = $this->pesanan(10449);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi-bulk', ['erp' => 1, 'so' => $a->sales_order_id . ',' . $b->sales_order_id]))
            ->assertOk()
            ->assertSee('SPXID10448')
            ->assertSee('SPXID10449');
    }

    public function test_tombol_label_erp_tanpa_resi_ditolak(): void
    {
        $this->jubelioMemberiUrl();
        $link = $this->pesanan(10450, ['tracking_no' => null]);

        $this->actingAs($this->admin())
            ->from('/erp/pos/fulfillment')
            ->get(route('pos.fulfillment.jubelio-resi', ['so' => $link->sales_order_id, 'erp' => 1]))
            ->assertRedirect('/erp/pos/fulfillment')
            ->assertSessionHas('error');

        $this->assertNull($link->fresh()->resi_printed_at);
    }

    public function test_cetak_massal_berhasil_menandai_semua(): void
    {
        $this->jubelioMemberiUrl();
        $this->reportJubelioPdf();
        $a = $this->pesanan(10434);
        $b = $this->pesanan(10435);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.jubelio-resi-bulk', ['so' => $a->sales_order_id . ',' . $b->sales_order_id]))
            ->assertOk();

        $this->assertNotNull($a->fresh()->resi_printed_at);
        $this->assertNotNull($b->fresh()->resi_printed_at);
    }
}
