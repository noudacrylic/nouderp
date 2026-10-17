<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel Pesanan CRM menampilkan tanggal bayar — patokan estimasi waktu kerja
 * (17 Okt 2026).
 */
class TanggalBayarPanelPesananTest extends TestCase
{
    use RefreshDatabase;

    private function chatDenganPesanan(): array
    {
        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();

        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $produk = Product::create(['sku' => 'CS3', 'name' => 'Box Charger', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true]);
        $pelanggan = Customer::create(['code' => 'C-ADI', 'name' => 'Adi']);

        $so = new SalesOrder();
        $so->forceFill(['order_number' => 'SO/2026/09/00012', 'status' => 'confirmed', 'order_date' => '2026-09-20',
            'customer_id' => $pelanggan->id, 'warehouse_id' => $gudang, 'grand_total' => 200000,
            'delivery_method' => 'kurir', 'global_discount_type' => 'nominal'])->save();
        $item = new \App\Modules\Sales\Models\SalesOrderItem();
        $item->forceFill(['sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 1, 'unit_price' => 200000,
            'discount_type' => 'nominal', 'net_unit_price' => 200000, 'line_subtotal' => 200000,
            'line_discount' => 0, 'line_total' => 200000])->save();

        $chat = CrmConversation::findOrCreateFor('6289691273916');
        $chat->forceFill(['customer_id' => $pelanggan->id])->save();

        return [$chat, $so, $pelanggan];
    }

    private function bayar(SalesOrder $so, string $tanggal, float $jumlah, string $nomor): void
    {
        SalesAdvance::create(['advance_number' => $nomor, 'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id, 'bank_account_id' => 1, 'advance_date' => $tanggal,
            'amount' => $jumlah, 'status' => 'posted']);
    }

    private function panel(CrmConversation $chat): array
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        return $this->actingAs($admin)->getJson(route('crm.inbox.pesanan', $chat))->assertOk()->json('pesanan.0');
    }

    public function test_dp_menampilkan_tanggal_dan_umur_hari(): void
    {
        [$chat, $so] = $this->chatDenganPesanan();
        $this->bayar($so, now()->subDays(3)->toDateString(), 100000, 'DP-1');

        $p = $this->panel($chat);

        $this->assertSame('DP', $p['bayar']['label']);
        $this->assertSame(3, $p['bayar']['hari']);
        $this->assertNull($p['bayar']['lunas']);
    }

    public function test_lunas_dua_kali_bayar_menampilkan_tanggal_lunas(): void
    {
        [$chat, $so] = $this->chatDenganPesanan();
        $this->bayar($so, now()->subDays(5)->toDateString(), 100000, 'DP-1');
        $this->bayar($so, now()->subDay()->toDateString(), 100000, 'DP-2');

        $p = $this->panel($chat);

        $this->assertSame('DP', $p['bayar']['label']);
        $this->assertSame(5, $p['bayar']['hari']);
        $this->assertNotNull($p['bayar']['lunas']);
    }

    public function test_belum_bayar_tanpa_tanggal(): void
    {
        [$chat] = $this->chatDenganPesanan();

        $this->assertNull($this->panel($chat)['bayar']);
    }
}
