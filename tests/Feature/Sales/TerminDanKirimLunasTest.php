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
 * Tautan bayar hanya untuk DP dan pelunasan; pembayaran di luar itu dicatat manual oleh admin.
 * Barang baru boleh keluar setelah lunas.
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

    /** Kasus Hanif: DP 2.251.000, mau bayar 2 juta dulu lewat tautan → ditolak, dicatat manual. */
    public function test_sesudah_dp_tautan_hanya_menerima_pelunasan_penuh(): void
    {
        $so = $this->so(['paid_amount' => 2251000]);

        // Termasuk tautan yang terlanjur mencatat batas 0 atau batas DP awal.
        foreach ([2251000, 0, 1800800, null] as $min) {
            $t = $this->tautan($so, $min);

            $this->get("/pay/{$t->link_token}")->assertOk()
                ->assertSee('dibayar <b>penuh</b>', false)->assertDontSee('Ingin membayar sebagian dulu?')->assertDontSee('Ketentuan pembayaran DP');

            foreach (['charge', 'snap'] as $jalur) {
                $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 2000000])
                    ->assertStatus(422)->assertJson(['error' => 'DP minimal Rp 2.251.000.']);
            }
        }

        // Tombol rincian pesanan & buat tautan menulis ulang batas tautan dari minDpAmount().
        $this->assertSame(2251000, $so->minDpAmount());
    }

    public function test_kesepakatan_tanpa_batas_tetap_boleh_sebagian(): void
    {
        $so = $this->so(['min_dp_percent' => 0]);
        $t = $this->tautan($so, 0);

        $this->get("/pay/{$t->link_token}")->assertOk()
            ->assertSee('Ingin membayar sebagian dulu?')->assertDontSee('DP 0%')->assertDontSee('Ketentuan pembayaran DP');

        foreach (['charge', 'snap'] as $jalur) {
            $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 0])->assertStatus(422)->assertJson(['error' => 'Isi nominal yang dibayar.']);
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

    /** Kasus Hanif: lunas lewat faktur, tautan lama masih pending → tidak boleh bayar lagi. */
    public function test_tautan_pada_pesanan_lunas_lewat_faktur_menolak_pembayaran(): void
    {
        $so = $this->so(['paid_amount' => 4251000]);
        $t = $this->tautan($so, 2251000);
        $this->assertSame(251000.0, $so->sisaTagihan());

        \App\Models\SalesInvoice::create(['invoice_number' => 'INV-T2', 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id,
            'invoice_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => 4502000, 'grand_total' => 4502000, 'paid_amount' => 251000, 'advance_applied' => 4251000]);
        $this->assertSame(0.0, $so->fresh()->sisaTagihan());

        $this->get("/pay/{$t->link_token}")->assertRedirect($so->fresh()->publicTrackUrl());
        foreach (['charge', 'snap'] as $jalur) {
            $this->postJson("/pay/{$t->link_token}/{$jalur}", ['channel' => 'qris', 'amount' => 251000])->assertStatus(422)->assertJson(['error' => 'Pesanan ini sudah lunas.']);
        }
        $this->assertSame('pending', $t->fresh()->status);
    }

    /** Faktur draft atau void bukan pembayaran. */
    public function test_faktur_belum_posting_atau_void_tidak_dihitung(): void
    {
        foreach (['draft', 'void'] as $i => $status) {
            $so = $this->so(['paid_amount' => 1000000]);
            \App\Models\SalesInvoice::create(['invoice_number' => 'INV-S' . $i, 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id,
                'invoice_date' => now()->toDateString(), 'status' => $status, 'subtotal' => 4502000, 'grand_total' => 4502000, 'paid_amount' => 4502000, 'advance_applied' => 0]);
            $this->assertSame(3502000.0, $so->sisaTagihan(), $status);
        }
    }

    private function produk(): int
    {
        return \App\Core\Inventory\Product::create(['sku' => 'P-T' . uniqid(), 'name' => 'Produk', 'sale_type' => 'bundle', 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true])->id;
    }
}
