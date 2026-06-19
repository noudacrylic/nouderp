<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rantai fulfillment WMS Jubelio (pick → faktur → resi). Memastikan: flag terset
 * berurutan, gagal di tengah meninggalkan flag step gagal = false (resume), dan
 * re-jalankan melanjutkan dari step yang gagal — bukan mengulang dari awal.
 */
class JubelioFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private const SO_ID = 2499709;

    protected function setUp(): void
    {
        parent::setUp();

        // Paksa baris singleton ke id=1 (di produksi selalu id=1; di test, AUTO_INCREMENT
        // InnoDB tidak ikut rollback antar-test sehingga singleton() bisa meleset bila
        // dibiarkan membuat id baru). forceFill agar bypass guard pada kolom id.
        $s = JubelioSetting::find(1) ?: new JubelioSetting();
        $s->forceFill([
            'id'                  => 1,
            'username'            => 'a@b.com',
            'password'            => 'secret',
            'is_active'           => true,
            'base_url'            => JubelioSetting::DEFAULT_BASE_URL,
            'default_location_id' => 1,
            'config'              => ['wms_picker_email' => 'picker@gudang.com'],
        ])->save();
    }

    private function makeLink(array $attrs = []): JubelioOrderLink
    {
        return JubelioOrderLink::create(array_merge([
            'jubelio_salesorder_id' => self::SO_ID,
            'jubelio_salesorder_no' => 'TP-INV/1',
            'sales_order_id'        => 1,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
        ], $attrs));
    }

    /** Semua endpoint sukses + AWB mengembalikan tracking_no. */
    private function fakeAllOk(): void
    {
        Http::fake([
            '*/login' => Http::response(['token' => 'tok'], 200),
            '*/wms/sales/ready-to-pick' => Http::response(['status' => 200], 200),
            '*/sales/picklists/items-to-pick' => Http::response([
                ['salesorder_id' => self::SO_ID, 'salesorder_detail_id' => 82, 'item_id' => 2,
                 'location_id' => 1, 'qty_ordered' => '3', 'bundle_item_id' => 0, 'end_qty' => '6'],
            ], 200),
            '*/wms/sales/picklists/' => Http::response(['status' => 'ok', 'data' => ['picks' => [['picklist_id' => 1, 'status' => 'ok']], 'invalidSO' => []]], 200),
            '*/wms/sales/packlist/mark-as-complete/' => Http::response(['status' => 'ok'], 200),
            '*/sales/packlists/create-invoice' => Http::response(['status' => 'ok', 'id' => 8], 200),
            '*/sales/request-awb-order/' => Http::response(['salesorder_id' => self::SO_ID, 'shipper' => 'JNE', 'tracking_no' => 'BLIJC02118495321'], 200),
        ]);
    }

    public function test_full_chain_sets_all_flags_and_tracking(): void
    {
        $this->fakeAllOk();
        $link = $this->makeLink();

        $res = app(JubelioFulfillmentService::class)->process($link);

        $this->assertTrue($res['success']);
        $this->assertSame('done', $res['stage']);

        $link->refresh();
        $this->assertTrue($link->j_ready_to_pick);
        $this->assertTrue($link->j_picklist_done);
        $this->assertTrue($link->j_packed);
        $this->assertTrue($link->j_invoice_done);
        $this->assertSame(8, (int) $link->j_invoice_id);
        $this->assertTrue($link->awb_requested);
        $this->assertSame('BLIJC02118495321', $link->tracking_no);
        $this->assertSame('JNE', $link->shipper);
        $this->assertNotNull($link->wms_completed_at);
        $this->assertNull($link->wms_last_error);
    }

    public function test_failure_midchain_leaves_failed_step_open_and_resumes(): void
    {
        // Picklist: panggilan pertama gagal (500), panggilan kedua (resume) sukses — pakai
        // sequence agar satu fake mendorong dua kali process(). Endpoint lain selalu ok.
        Http::fake([
            '*/login' => Http::response(['token' => 'tok'], 200),
            '*/wms/sales/ready-to-pick' => Http::response(['status' => 200], 200),
            '*/sales/picklists/items-to-pick' => Http::response([
                ['salesorder_id' => self::SO_ID, 'salesorder_detail_id' => 82, 'item_id' => 2,
                 'location_id' => 1, 'qty_ordered' => '3'],
            ], 200),
            '*/wms/sales/picklists/' => Http::sequence()
                ->push(['message' => 'boom'], 500)
                ->push(['status' => 'ok', 'data' => ['picks' => [['picklist_id' => 1, 'status' => 'ok']], 'invalidSO' => []]], 200),
            '*/wms/sales/packlist/mark-as-complete/' => Http::response(['status' => 'ok'], 200),
            '*/sales/packlists/create-invoice' => Http::response(['status' => 'ok', 'id' => 8], 200),
            '*/sales/request-awb-order/' => Http::response(['shipper' => 'JNE', 'tracking_no' => 'BLIJC02118495321'], 200),
        ]);

        $link = $this->makeLink();
        $res = app(JubelioFulfillmentService::class)->process($link);

        $this->assertFalse($res['success']);
        $link->refresh();
        $this->assertTrue($link->j_ready_to_pick, 'step 1 sukses tetap tercatat');
        $this->assertFalse($link->j_picklist_done, 'step gagal dilepas → bisa diulang');
        $this->assertNotNull($link->wms_last_error);
        $this->assertNull($link->tracking_no);

        // Resume: melanjutkan dari picklist (kini sukses) sampai tuntas.
        $res2 = app(JubelioFulfillmentService::class)->process($link->fresh());
        $this->assertTrue($res2['success']);
        $this->assertSame('BLIJC02118495321', $link->fresh()->tracking_no);
    }

    public function test_completed_chain_is_noop_on_rerun(): void
    {
        $this->fakeAllOk();
        $link = $this->makeLink();
        app(JubelioFulfillmentService::class)->process($link);

        Http::fake(); // matikan semua HTTP — rerun tidak boleh menembak API lagi
        $res = app(JubelioFulfillmentService::class)->process($link->fresh());

        $this->assertTrue($res['success']);
        $this->assertSame('done', $res['stage']);
        Http::assertNothingSent();
    }

    public function test_missing_picker_email_fails_fast(): void
    {
        JubelioSetting::singleton()->update(['config' => []]);
        Http::fake(['*/login' => Http::response(['token' => 'tok'], 200)]);

        $link = $this->makeLink();
        $res = app(JubelioFulfillmentService::class)->process($link);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('picker', strtolower($res['message']));
        $this->assertFalse($link->fresh()->j_ready_to_pick);
    }
}
