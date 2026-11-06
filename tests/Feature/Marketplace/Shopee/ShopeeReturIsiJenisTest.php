<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jenis draft retur Shopee diisi dari pengajuan retur Shopee-nya — tanpa menimpa pilihan manual.
 */
class ShopeeReturIsiJenisTest extends TestCase
{
    use RefreshDatabase;

    private Customer $cust;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cust = Customer::create(['code' => 'SHP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true]);
    }

    private function toko(): ShopeeShop
    {
        return (new ShopeeShop())->forceFill(['env' => 'test', 'shop_id' => 777]);
    }

    private function draft(string $sn, ?string $jenis = null, string $status = 'draft'): SalesReturn
    {
        $so = SalesOrder::create([
            'order_number' => "SP-$sn", 'customer_id' => $this->cust->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal', 'status' => 'confirmed',
            'subtotal' => 100000, 'grand_total' => 100000, 'paid_amount' => 100000, 'delivery_method' => 'kurir',
        ]);

        return SalesReturn::create([
            'return_number' => 'SR-' . $sn, 'customer_id' => $this->cust->id, 'sales_order_id' => $so->id,
            'return_date' => now()->toDateString(), 'grand_total' => 100000, 'status' => $status, 'stage' => 'baru',
            'return_type' => $jenis, 'notes' => 'catatan lama',
        ]);
    }

    private function pengajuan(string $sn, string $rsn, string $status, string $reason, ?string $teks = null): void
    {
        ShopeeReturn::create([
            'env' => 'test', 'shop_id' => 777, 'return_sn' => $rsn, 'order_sn' => $sn, 'status' => $status,
            'reason' => $reason, 'text_reason' => $teks, 'refund_amount' => 100000, 'raw' => [], 'dibuat_shopee_at' => now(),
        ]);
    }

    public function test_jenis_diisi_dari_pengajuan_shopee(): void
    {
        $rusak    = $this->draft('A1');
        $hilang   = $this->draft('A2');
        $batal    = $this->draft('A3');
        $tanpa    = $this->draft('A4');
        $manual   = $this->draft('A5', 'gagal_kirim');
        $posted   = $this->draft('A6', null, 'posted');

        $this->pengajuan('A1', 'R1', 'PROCESSING', 'BROKEN_PRODUCTS', 'Pecah sedikit');
        $this->pengajuan('A2', 'R2', 'ACCEPTED', 'NOT_RECEIPT');
        $this->pengajuan('A3', 'R3', 'CANCELLED', 'CHANGE_MIND');
        $this->pengajuan('A5', 'R5', 'ACCEPTED', 'WRONG_ITEM');
        $this->pengajuan('A6', 'R6', 'ACCEPTED', 'WRONG_ITEM');

        $hasil = app(ShopeeReturService::class)->isiJenis($this->toko());

        $this->assertSame(['SR-A1' => 'diajukan_konsumen', 'SR-A2' => 'paket_hilang'], $hasil);

        $rusak->refresh();
        $this->assertSame('diajukan_konsumen', $rusak->return_type);
        $this->assertSame('R1', $rusak->external_return_number);
        $this->assertStringStartsWith("catatan lama\n[Data Shopee ", $rusak->notes);
        $this->assertStringContainsString('Alasan pembeli: Barang pecah / rusak — "Pecah sedikit"', $rusak->notes);

        $this->assertSame('paket_hilang', $hilang->refresh()->return_type);
        $this->assertNull($batal->refresh()->return_type);
        $this->assertNull($tanpa->refresh()->return_type);
        $this->assertSame('gagal_kirim', $manual->refresh()->return_type);
        $this->assertNull($posted->refresh()->return_type);

        // Jalan kedua tak mengulang catatan.
        $this->assertSame([], app(ShopeeReturService::class)->isiJenis($this->toko()));
    }
}
