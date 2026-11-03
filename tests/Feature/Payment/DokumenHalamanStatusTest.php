<?php

namespace Tests\Feature\Payment;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MidtransTransaction;
use App\Models\SalesInvoice;
use App\Models\StorefrontSetting;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tautan bayar tanpa tagihan mengantar ke halaman status; di sana pembeli mengunduh SO & invoice sendiri.
 */
class DokumenHalamanStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $nomor = 0;

    private function so(array $tambahan = []): SalesOrder
    {
        $this->nomor++;

        return SalesOrder::create($tambahan + [
            'order_number' => 'SO-DOK-' . $this->nomor,
            'customer_id' => Customer::create(['code' => 'CD' . $this->nomor, 'name' => 'Pembeli', 'is_active' => true])->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'global_discount_type' => 'nominal',
            'subtotal' => 500000, 'grand_total' => 500000, 'paid_amount' => 0,
        ]);
    }

    private function faktur(SalesOrder $so, string $status = 'posted', float $dibayar = 500000): SalesInvoice
    {
        $this->nomor++;

        return SalesInvoice::create(['invoice_number' => 'INV-DOK-' . $this->nomor, 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id,
            'invoice_date' => now()->toDateString(), 'status' => $status, 'subtotal' => 500000, 'grand_total' => 500000, 'paid_amount' => $dibayar]);
    }

    private function tautan(array $rujukan): MidtransTransaction
    {
        return MidtransTransaction::create($rujukan + ['order_id' => 'NOUD-D' . uniqid(), 'source' => 'link', 'link_token' => 'tok' . uniqid(),
            'channel' => 'snap_auto', 'gross_amount' => 0, 'base_amount' => 0, 'customer_admin_fee' => 0, 'status' => 'pending']);
    }

    private function dokumen(string $token): array
    {
        $kunci = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $kunci]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $kunci])
            ->getJson("/api/storefront/orders/{$token}")->assertOk()->json('data.documents');
    }

    public function test_tautan_pesanan_lunas_diantar_ke_halaman_status_dan_tokennya_dibuat(): void
    {
        $so = $this->so(['paid_amount' => 500000]);
        $t = $this->tautan(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);
        $this->assertNull($so->public_token);

        $this->get("/pay/{$t->link_token}")->assertRedirect($so->fresh()->publicTrackUrl());

        $this->assertNotNull($so->fresh()->public_token);
    }

    public function test_tautan_yang_masih_bertagihan_tetap_di_halaman_bayar(): void
    {
        $so = $this->so(['paid_amount' => 100000]);
        $t = $this->tautan(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);

        $this->get("/pay/{$t->link_token}")->assertOk()->assertSee($so->order_number);
    }

    public function test_tautan_faktur_lunas_diantar_tapi_faktur_tanpa_pesanan_tetap_di_tempat(): void
    {
        $so = $this->so();
        $f = $this->faktur($so);
        $t = $this->tautan(['sales_invoice_id' => $f->id, 'customer_id' => $so->customer_id]);
        $this->get("/pay/{$t->link_token}")->assertRedirect($so->fresh()->publicTrackUrl());

        $tanpaSo = SalesInvoice::create(['invoice_number' => 'INV-LEPAS', 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id,
            'invoice_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => 500000, 'grand_total' => 500000, 'paid_amount' => 500000]);
        $t2 = $this->tautan(['sales_invoice_id' => $tanpaSo->id, 'customer_id' => $so->customer_id]);
        $this->get("/pay/{$t2->link_token}")->assertOk()->assertSee('INV-LEPAS');
    }

    public function test_halaman_status_mencantumkan_so_dan_semua_faktur_posted(): void
    {
        $so = $this->so();
        $so->ensurePublicToken();
        $satu = $this->faktur($so);
        $dua = $this->faktur($so);
        $this->faktur($so, 'draft');
        $this->faktur($so, 'void');

        $dok = $this->dokumen($so->public_token);

        $this->assertSame(['so', 'invoice', 'invoice'], array_column($dok, 'type'));
        $this->assertSame([$so->order_number, $satu->invoice_number, $dua->invoice_number], array_column($dok, 'number'));
        $this->assertStringContainsString("/pesanan-dokumen/{$so->public_token}/invoice/{$dua->id}.pdf", $dok[2]['url']);
    }

    public function test_belum_ada_faktur_hanya_nota_pesanan_dan_pesanan_batal_kosong(): void
    {
        $so = $this->so();
        $so->ensurePublicToken();
        $this->assertSame(['so'], array_column($this->dokumen($so->public_token), 'type'));

        $so->update(['status' => 'void']);
        $this->assertSame([], $this->dokumen($so->public_token));
        $this->get("/pesanan-dokumen/{$so->public_token}/so.pdf")->assertNotFound();
    }

    public function test_unduhan_ditolak_untuk_faktur_yang_bukan_miliknya_atau_belum_posted(): void
    {
        $so = $this->so();
        $so->ensurePublicToken();
        $lain = $this->faktur($this->so());
        $draft = $this->faktur($so, 'draft');

        $this->get("/pesanan-dokumen/{$so->public_token}/invoice/{$lain->id}.pdf")->assertNotFound();
        $this->get("/pesanan-dokumen/{$so->public_token}/invoice/{$draft->id}.pdf")->assertNotFound();
        $this->get('/pesanan-dokumen/token-asing/so.pdf')->assertNotFound();
    }
}
