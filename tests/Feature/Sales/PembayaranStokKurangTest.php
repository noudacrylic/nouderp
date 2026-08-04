<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tautan pembayaran boleh disebar sejak SO masih draft, dan draft BELUM menahan stok.
 *
 * Kasus yang dijaga: sisa barang 2, satu pembeli dikirimi tautan untuk 2 unit dan satu
 * lagi untuk 1 unit. Siapa pun yang membayar belakangan tidak boleh membuat stok minus —
 * kecuali memang ada kesepakatan keep stock dengan pembeli itu.
 */
class PembayaranStokKurangTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;
    private int $customerId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        MidtransSetting::singleton()->update(['server_key' => 'KUNCI-UJI', 'is_production' => false]);
        Http::fake();

        $this->warehouseId = Warehouse::create(['name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true])->id;
        $this->customerId = Customer::create(['code' => 'CUST-STOK', 'name' => 'Pembeli', 'is_active' => true])->id;
        $this->productId = Product::create([
            'sku' => 'AKR-1', 'name' => 'Akrilik A4', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        ProductStock::create([
            'product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'qty_on_hand' => 2,
        ]);
    }

    private function so(float $qty, array $attrs = []): SalesOrder
    {
        $so = SalesOrder::create(array_merge([
            'order_number' => 'SO-STOK-' . uniqid(),
            'customer_id' => $this->customerId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => 'draft',
            'grand_total' => 100000 * $qty,
            'paid_amount' => 0,
        ], $attrs));

        SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $this->productId,
            'qty' => $qty,
            'unit_price' => 100000,
            'net_unit_price' => 100000,
            'discount_per_unit' => 0,
            'line_subtotal' => 100000 * $qty,
            'line_discount' => 0,
            'line_total' => 100000 * $qty,
            'conversion_to_base' => 1,
        ]);

        return $so->fresh();
    }

    private function link(SalesOrder $so, string $token): MidtransTransaction
    {
        return MidtransTransaction::forceCreate([
            'order_id' => 'NOUD-SODP-' . strtoupper($token),
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'source' => 'link',
            'channel' => 'snap_auto',
            'status' => 'pending',
            'gross_amount' => 0,
            'base_amount' => 0,
            'link_token' => $token,
            'expired_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_stok_cukup_tetap_bisa_bayar(): void
    {
        $trx = $this->link($this->so(2), 'token-cukup');

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('Nominal yang Dibayar')
            ->assertDontSee('tidak mencukupi');
    }

    public function test_pembeli_kedua_ditolak_setelah_stok_diambil_yang_pertama(): void
    {
        // Pembeli A memesan 2 dan sudah membayar → SO dikonfirmasi, stoknya ditahan.
        $soA = $this->so(2, ['status' => 'confirmed']);
        StockReservation::create([
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'sales_order_id' => $soA->id,
            'qty' => 2,
            'status' => 'active',
        ]);

        // Pembeli B memegang tautan untuk 1 unit dari sisa yang sama.
        $trxB = $this->link($this->so(1), 'token-kalah');

        $res = $this->get("/pay/{$trxB->link_token}");
        $res->assertOk()
            ->assertSee('Stok tersisa tidak mencukupi')
            ->assertSee('hubungi admin')
            ->assertDontSee('Nominal yang Dibayar');

        // Penjaga sisi server: halaman lama yang dibuka sebelum stok habis pun ditolak.
        $this->postJson("/pay/{$trxB->link_token}/snap", ['channel' => 'qris'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'tidak mencukupi'));

        $this->postJson("/pay/{$trxB->link_token}/charge", ['channel' => 'qris'])->assertStatus(422);
    }

    public function test_pesanan_melebihi_stok_langsung_ditolak(): void
    {
        $trx = $this->link($this->so(5), 'token-lebih');

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('Stok tersisa tidak mencukupi')
            ->assertSee('dipesan 5');
    }

    public function test_keep_stock_membebaskan_pembayaran(): void
    {
        $trx = $this->link($this->so(5, ['allow_backorder' => true]), 'token-keepstock');

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('Nominal yang Dibayar')
            ->assertDontSee('tidak mencukupi');

        // Apa pun hasil panggilan ke Midtrans (di-fake), penolakan karena stok tidak boleh muncul.
        $res = $this->postJson("/pay/{$trx->link_token}/snap", ['channel' => 'qris']);
        $this->assertStringNotContainsString('tidak mencukupi', (string) $res->json('error'));
    }

    public function test_produk_preorder_tidak_ikut_dihalangi(): void
    {
        // Preorder memang dibuat setelah dipesan — produksinya justru menunggu DP masuk.
        Product::whereKey($this->productId)->update(['sale_type' => 'preorder']);

        $trx = $this->link($this->so(10), 'token-preorder');

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('Nominal yang Dibayar');
    }

    public function test_tombol_keep_stock_bisa_dinyalakan_dari_halaman_so(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $so = $this->so(5, ['status' => 'confirmed']);

        $this->get("/erp/sales/orders/{$so->id}")
            ->assertOk()
            ->assertSee('Keep stock')
            ->assertSee('stok tidak cukup');

        $this->post("/erp/sales/orders/{$so->id}/keep-stock", ['allow_backorder' => 1]);

        $this->assertTrue($so->fresh()->allow_backorder);
    }
}
