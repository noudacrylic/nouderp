<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MidtransTransaction;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kesepakatan DP mengikat pembayaran pertama saja; termin berikutnya bebas. Dicicil berapa kali
 * pun, barang baru boleh keluar setelah lunas.
 */
class TerminDanKirimLunasTest extends TestCase
{
    use RefreshDatabase;

    private int $nomor = 0;

    private function so(array $tambahan = [], bool $marketplace = false): SalesOrder
    {
        $this->nomor++;

        return SalesOrder::create($tambahan + [
            'order_number' => 'SO-T' . $this->nomor,
            'customer_id' => Customer::create(['code' => 'CT' . $this->nomor, 'name' => 'Hanif', 'is_active' => true, 'is_marketplace' => $marketplace])->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang T'])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'global_discount_type' => 'nominal',
            'subtotal' => 4502000, 'grand_total' => 4502000, 'paid_amount' => 0, 'min_dp_percent' => 40,
        ]);
    }

    private function tautan(SalesOrder $so, ?int $min): MidtransTransaction
    {
        return MidtransTransaction::create(['order_id' => 'NOUD-T' . uniqid(), 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'source' => 'link',
            'link_token' => 'tok' . uniqid(), 'channel' => 'snap_auto', 'gross_amount' => 0, 'base_amount' => 0, 'min_dp_amount' => $min, 'customer_admin_fee' => 0, 'status' => 'pending']);
    }

    public function test_pembayaran_pertama_wajib_sesuai_kesepakatan(): void
    {
        $so = $this->so();
        $t = $this->tautan($so, $so->minDpAmount());
        $this->assertSame(1800800, $so->minDpAmount());

        foreach (['charge', 'snap'] as $jalur) {
            $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 1000000])
                ->assertStatus(422)->assertJson(['error' => 'DP minimal Rp 1.800.800.']);
        }
    }

    /** Kasus Hanif: DP 2.251.000, tautan lama mencatat wajib lunas, mau bayar 2 juta dulu. */
    public function test_termin_berikutnya_bebas_walau_tautan_lama_mencatat_wajib_lunas(): void
    {
        $so = $this->so(['paid_amount' => 2251000]);
        $t = $this->tautan($so, 2251000);
        $this->assertSame(0, $so->minDpAmount());

        $this->get("/pay/{$t->link_token}")->assertOk()
            ->assertSee('Ingin membayar sebagian dulu?')->assertDontSee('DP 0%')->assertDontSee('Ketentuan pembayaran DP');

        // Lolos penjaga nominal: gagalnya (bila ada) dari pemanggilan Midtrans, bukan batas DP.
        foreach (['charge', 'snap'] as $jalur) {
            $r = $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 2000000]);
            $this->assertStringNotContainsString('DP minimal', (string) $r->json('error'), $jalur);
            $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 0])->assertStatus(422)->assertJson(['error' => 'Isi nominal yang dibayar.']);
            $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 2251001])->assertStatus(422);
        }
    }

    public function test_barang_tak_boleh_keluar_sebelum_lunas(): void
    {
        $so = $this->so(['paid_amount' => 4251000]);
        $sj = SalesDelivery::create(['delivery_number' => 'SJ-T1', 'sales_order_id' => $so->id, 'warehouse_id' => $so->warehouse_id,
            'customer_id' => $so->customer_id, 'delivery_date' => now()->toDateString(), 'status' => 'draft']);
        $sj->items()->create(['product_id' => $this->produk(), 'qty' => 1]);

        try {
            app(SalesDeliveryService::class)->post($sj->id);
            $this->fail('Seharusnya ditolak.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('belum lunas. Sisa Rp 251.000', $e->getMessage());
        }
        $this->assertSame('draft', $sj->fresh()->status);

        // Lunas lewat termin terakhir → boleh.
        $so->update(['paid_amount' => 4502000]);
        $this->assertNull($so->fresh()->alasanTakBolehDikirim());
    }

    public function test_pengecualian_tempo_marketplace_dan_pelunasan_lewat_faktur(): void
    {
        $this->assertNull($this->so(['is_tempo' => true])->alasanTakBolehDikirim());
        $this->assertNull($this->so([], true)->alasanTakBolehDikirim());

        $so = $this->so(['paid_amount' => 2251000]);
        $this->assertNotNull($so->alasanTakBolehDikirim());
        \App\Models\SalesInvoice::create(['invoice_number' => 'INV-T1', 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id,
            'invoice_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => 4502000, 'grand_total' => 4502000, 'paid_amount' => 2251000, 'advance_applied' => 2251000]);
        $this->assertNull($so->fresh()->alasanTakBolehDikirim());
    }

    private function produk(): int
    {
        return \App\Core\Inventory\Product::create(['sku' => 'P-T' . uniqid(), 'name' => 'Produk', 'sale_type' => 'bundle', 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true])->id;
    }
}
