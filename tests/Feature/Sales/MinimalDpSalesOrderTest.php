<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batas minimal DP adalah kesepakatan penjualan, jadi tempatnya di Sales Order.
 *
 * Bawaannya 50%, tapi ada pelanggan yang disepakati lebih rendah. Sebelumnya batas itu
 * hanya bisa diketik saat tautan dibuat dan tidak terlihat di SO — tidak ada jejak
 * kesepakatannya, dan tiap tautan baru kembali ke 50%.
 */
class MinimalDpSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private function so(array $attrs = []): SalesOrder
    {
        $cust = Customer::create(['code' => 'CUST-DP-' . uniqid(), 'name' => 'Pembeli DP', 'is_active' => true]);

        return SalesOrder::create(array_merge([
            'order_number' => 'SO-MINDP-' . uniqid(),
            'customer_id' => $cust->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => 'confirmed',
            'grand_total' => 1000000,
            'paid_amount' => 0,
        ], $attrs));
    }

    public function test_tanpa_kesepakatan_memakai_bawaan_50_persen(): void
    {
        $so = $this->so();

        $this->assertSame(500000, $so->minDpAmount());
        $this->assertSame(50.0, $so->minDpPercent());
        $this->assertFalse($so->hasCustomMinDp());
    }

    public function test_kesepakatan_persen_dipakai_dan_ikut_total(): void
    {
        $so = $this->so(['min_dp_percent' => 30]);

        $this->assertSame(300000, $so->minDpAmount());
        $this->assertTrue($so->hasCustomMinDp());

        // Item direvisi → total naik, batas persentase ikut menyesuaikan sendiri.
        $so->update(['grand_total' => 2000000]);
        $this->assertSame(600000, $so->fresh()->minDpAmount());
    }

    public function test_kesepakatan_nominal_tidak_ikut_total(): void
    {
        $so = $this->so(['min_dp_amount' => 250000]);

        $this->assertSame(250000, $so->minDpAmount());
        $this->assertSame(25.0, $so->minDpPercent());

        $so->update(['grand_total' => 2000000]);
        $this->assertSame(250000, $so->fresh()->minDpAmount(), 'Nominal yang disepakati tidak boleh ikut bergeser.');
    }

    public function test_nol_berarti_tanpa_batas_minimal(): void
    {
        $so = $this->so(['min_dp_percent' => 0]);

        $this->assertSame(0, $so->minDpAmount());
        $this->assertTrue($so->hasCustomMinDp(), 'Nol adalah kesepakatan, bukan "belum diisi".');
    }

    public function test_yang_sudah_dibayar_mengurangi_batas_dan_dibatasi_sisa(): void
    {
        // Sudah DP 200rb dari batas 300rb → tinggal 100rb lagi untuk memenuhi batas.
        $so = $this->so(['min_dp_percent' => 30, 'paid_amount' => 200000]);
        $this->assertSame(100000, $so->minDpAmount());

        // Sudah melampaui batas → tidak ada lagi minimal yang harus dipenuhi.
        $so2 = $this->so(['min_dp_percent' => 30, 'paid_amount' => 400000]);
        $this->assertSame(0, $so2->minDpAmount());

        // Batas 900rb, sudah dibayar 800rb → tinggal 100rb, walau sisa tagihannya 200rb.
        $so3 = $this->so(['min_dp_amount' => 900000, 'paid_amount' => 800000]);
        $this->assertSame(100000, $so3->minDpAmount());

        // Batas melebihi total pesanan → dipotong ke sisa tagihan, tidak boleh lebih.
        $so4 = $this->so(['min_dp_amount' => 1200000]);
        $this->assertSame(1000000, $so4->minDpAmount());
    }

    public function test_tautan_dp_memakai_batas_dari_so(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $so = $this->so(['min_dp_percent' => 20]);

        $res = $this->getJson("/erp/sales/payment/sales-order/{$so->id}/midtrans-link");

        $res->assertOk();
        $this->assertSame(200000, $res->json('min_dp'));
        $this->assertTrue($res->json('min_dp_custom'));
        $this->assertSame(200000, (int) round(\App\Models\MidtransTransaction::where('sales_order_id', $so->id)->value('min_dp_amount')));
    }

    public function test_kesepakatan_tersimpan_saat_so_disimpan(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $customerId = Customer::create(['code' => 'CUST-SIMPAN', 'name' => 'Pembeli', 'is_active' => true])->id;
        $productId = \App\Core\Inventory\Product::create([
            'sku' => 'PRD-DP-1', 'name' => 'Produk DP', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        $form = [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $productId,
                'qty' => 10,
                'unit_price' => '100.000',
                'discount_type' => 'nominal',
                'discount_value' => 0,
            ]],
        ];

        // Kesepakatan persen.
        $this->post('/erp/sales/orders', $form + [
            'min_dp_basis' => 'percent',
            'min_dp_percent' => '30',
            'min_dp_amount' => '300.000',
        ]);

        $so = SalesOrder::latest('id')->first();
        $this->assertSame(30.0, (float) $so->min_dp_percent);
        $this->assertNull($so->min_dp_amount, 'Hanya satu kolom yang boleh terisi.');
        $this->assertSame(300000, $so->minDpAmount());

        // Diubah jadi kesepakatan nominal lewat edit.
        $this->put("/erp/sales/orders/{$so->id}", $form + [
            'min_dp_basis' => 'nominal',
            'min_dp_percent' => '25',
            'min_dp_amount' => '250.000',
        ]);

        $so->refresh();
        $this->assertNull($so->min_dp_percent);
        $this->assertSame(250000.0, (float) $so->min_dp_amount);

        // Dikosongkan lagi → kembali ke bawaan 50%.
        $this->put("/erp/sales/orders/{$so->id}", $form + [
            'min_dp_basis' => 'percent',
            'min_dp_percent' => '',
            'min_dp_amount' => '',
        ]);

        $so->refresh();
        $this->assertFalse($so->hasCustomMinDp());
        $this->assertSame(500000, $so->minDpAmount());
    }

    public function test_kolom_minimal_dp_tampil_di_create_edit_dan_view(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->get('/erp/sales/orders/create')->assertOk()->assertSee('Minimal DP');

        $so = $this->so(['status' => 'draft', 'min_dp_percent' => 30]);

        $this->get("/erp/sales/orders/{$so->id}/edit")
            ->assertOk()
            ->assertSee('Minimal DP')
            ->assertSee('name="min_dp_percent"', false);

        $this->get("/erp/sales/orders/{$so->id}")
            ->assertOk()
            ->assertSee('Minimal DP')
            ->assertSee('Kesepakatan');
    }

    public function test_halaman_bayar_menawarkan_dp_sesuai_kesepakatan(): void
    {
        $so = $this->so(['min_dp_percent' => 20]);

        $trx = \App\Models\MidtransTransaction::forceCreate([
            'order_id' => 'NOUD-SODP-MIN',
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'source' => 'link',
            'channel' => 'snap_auto',
            'status' => 'pending',
            'gross_amount' => 0,
            'base_amount' => 0,
            'min_dp_amount' => null, // tautan lama: belum menyimpan batas apa pun
            'link_token' => 'token-min-dp',
            'expired_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tautan tanpa batas tersimpan harus jatuh ke kesepakatan SO (20%), bukan 50%.
        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('200.000');
    }
}
