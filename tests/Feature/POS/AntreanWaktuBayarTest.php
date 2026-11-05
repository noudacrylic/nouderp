<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\SalesOrderStockCheck;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jatah stok mengikuti waktu bayar pertama, bukan umur SO. Kasus nyata 5 Nov 2026: SO dibuat
 * 27 Okt dan baru dibayar 5 Nov mendahului pesanan yang sudah dibayar 31 Okt.
 */
class AntreanWaktuBayarTest extends TestCase
{
    use RefreshDatabase;

    private function gudang(): Warehouse
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);
    }

    private function produk(int $stok): Product
    {
        $p = Product::create(['sku' => 'BC-' . uniqid(), 'name' => 'Box Charger', 'sale_type' => 'preorder',
            'made_to_order' => false, 'preorder_stock' => 100, 'lead_time_days' => 7]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang()->id, 'qty_on_hand' => $stok]);

        return $p;
    }

    private function so(Product $p, string $dibuat, array $attrs = []): SalesOrder
    {
        $cust = Customer::create(['code' => 'C-' . uniqid(), 'name' => 'Toko', 'is_marketplace' => false, 'is_active' => true]);
        $so = SalesOrder::create($attrs + ['order_number' => 'SO-' . uniqid(), 'customer_id' => $cust->id, 'warehouse_id' => $this->gudang()->id,
            'order_date' => Carbon::parse($dibuat)->toDateString(), 'global_discount_type' => 'nominal', 'status' => 'confirmed', 'delivery_method' => 'kurir',
            'grand_total' => 100000, 'paid_amount' => 100000, 'measured_at' => now(), 'package_weight_gram' => 1000]);
        DB::table('sales_orders')->where('id', $so->id)->update(['created_at' => $dibuat]);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'qty' => 1, 'conversion_to_base' => 1, 'unit_price' => 100000,
            'net_unit_price' => 100000, 'line_subtotal' => 100000, 'line_discount' => 0, 'line_total' => 100000]);
        StockReservation::create(['product_id' => $p->id, 'warehouse_id' => $so->warehouse_id, 'sales_order_id' => $so->id, 'qty' => 1, 'status' => 'active']);

        return $so->refresh();
    }

    private function bayar(SalesOrder $so, string $waktu, string $status = 'posted'): void
    {
        DB::table('customer_payments')->insert(['payment_number' => 'PAY-' . uniqid(), 'customer_id' => $so->customer_id, 'date' => Carbon::parse($waktu)->toDateString(),
            'cash_account_id' => DB::table('accounts')->value('id') ?? DB::table('accounts')->insertGetId(['code' => 'K' . uniqid(), 'name' => 'Kas', 'type' => 'asset']),
            'amount' => 100000, 'payment_type' => 'advance', 'sales_order_id' => $so->id, 'status' => $status, 'created_at' => $waktu, 'updated_at' => $waktu]);
    }

    private function kurang(SalesOrder ...$orders): array
    {
        return app(SalesOrderStockCheck::class)->shortagesForFulfillment(
            collect($orders)->map(fn ($s) => $s->load('items.product'))->all()
        );
    }

    public function test_so_lama_yang_baru_dibayar_tidak_mendahului_yang_sudah_bayar(): void
    {
        $p = $this->produk(1);
        $lama = $this->so($p, '2026-10-27 11:00:00');
        $baru = $this->so($p, '2026-10-30 13:00:00');
        $this->bayar($lama, '2026-11-05 10:30:00');
        $this->bayar($baru, '2026-10-31 12:00:00');

        $kurang = $this->kurang($lama, $baru);

        $this->assertArrayHasKey($lama->id, $kurang, 'Baru bayar 5 Nov: menunggu giliran.');
        $this->assertArrayNotHasKey($baru->id, $kurang, 'Bayar 31 Okt: dapat jatah lebih dulu.');
    }

    public function test_pesanan_belum_bayar_tidak_menahan_yang_sudah_bayar(): void
    {
        $p = $this->produk(1);
        $belum = $this->so($p, '2026-10-20 09:00:00', ['paid_amount' => 0]);
        $sudah = $this->so($p, '2026-10-30 09:00:00');
        $this->bayar($sudah, '2026-10-31 09:00:00');

        $kurang = $this->kurang($belum, $sudah);

        $this->assertArrayNotHasKey($sudah->id, $kurang);
        $this->assertArrayHasKey($belum->id, $kurang);
    }

    public function test_pembayaran_void_tidak_dihitung_dan_yang_pertama_dipakai(): void
    {
        $p = $this->produk(1);
        $a = $this->so($p, '2026-10-27 09:00:00');
        $this->bayar($a, '2026-10-28 09:00:00', 'void');
        $this->bayar($a, '2026-11-05 09:00:00');
        $this->bayar($a, '2026-11-06 09:00:00');

        $this->assertSame('2026-11-05 09:00:00', app(SalesOrderStockCheck::class)->waktuAntre([$a->id])[$a->id]);
    }

    public function test_tanpa_catatan_bayar_memakai_waktu_so_dan_seri_memakai_id(): void
    {
        $p = $this->produk(1);
        $a = $this->so($p, '2026-10-27 09:00:00');
        $b = $this->so($p, '2026-10-27 09:00:00');

        $kurang = $this->kurang($a, $b);

        $this->assertArrayNotHasKey($a->id, $kurang);
        $this->assertArrayHasKey($b->id, $kurang);
    }

    public function test_batas_kirim_dihitung_dari_waktu_bayar_dan_tanggal_dokumen_tetap(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
        $p = $this->produk(1);
        $so = $this->so($p, '2026-10-27 11:00:00');
        $this->bayar($so, '2026-11-05 10:30:00');

        $baris = app(FulfillmentReadinessService::class)->bucket('perlu_diproses')->firstWhere('id', $so->id);

        $this->assertSame('2026-11-12', Carbon::parse($baris['deadline'])->toDateString(), 'Bayar 5 Nov + 7 hari, bukan 27 Okt + 7 hari.');
        $this->assertFalse($baris['is_overdue']);
        $this->assertSame('2026-10-27', Carbon::parse($so->fresh()->order_date)->toDateString());
    }
}
