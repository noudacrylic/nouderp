<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioFulfillmentService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesanan yang pembelinya minta batal (atau sudah batal di marketplace) tidak boleh
 * diteruskan packing. Dua penjaga: penanda merah di kartu, dan penolakan di server
 * untuk semua jalur Proses. Keputusan batal milik admin.
 */
class PenandaBatalPackingTest extends TestCase
{
    use RefreshDatabase;

    private function pesanan(array $link = []): array
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Shopee Official', 'is_marketplace' => true, 'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'order_number' => 'SO-BTL-' . uniqid(), 'customer_id' => $cust->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 100000, 'paid_amount' => 100000,
        ]);
        $l = JubelioOrderLink::create(array_merge([
            'jubelio_salesorder_id' => 9901, 'jubelio_salesorder_no' => 'SP-9901',
            'sales_order_id' => $so->id, 'store' => 'Shopee', 'dp_posted' => true,
        ], $link));

        return [$so, $l];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_kartu_perlu_diproses_diberi_penanda_dan_tombol_proses_dikunci(): void
    {
        $this->pesanan(['cancel_requested' => true, 'cancel_reason' => 'Ganti alamat']);

        $this->actingAs($this->admin())->get(route('pos.fulfillment.perlu-diproses'))->assertOk()
            ->assertSee('data-penanda-batal', false)
            ->assertSee('PEMBELI MINTA BATAL')
            ->assertSee('Alasan pembeli: Ganti alamat')
            ->assertSee('Tunggu keputusan admin')
            ->assertDontSee('🛒 Proses Pesanan');
    }

    public function test_pesanan_normal_tanpa_penanda(): void
    {
        $this->pesanan();

        $this->actingAs($this->admin())->get(route('pos.fulfillment.perlu-diproses'))->assertOk()
            ->assertDontSee('data-penanda-batal', false)
            ->assertSee('🛒 Proses Pesanan');
    }

    public function test_server_menolak_proses_pesanan_yang_diminta_batal(): void
    {
        [, $link] = $this->pesanan(['cancel_requested' => true]);

        $hasil = app(JubelioFulfillmentService::class)->process($link);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('PEMBELI MINTA BATAL', $hasil['message']);
        $this->assertFalse((bool) $link->fresh()->j_ready_to_pick, 'rantai WMS tidak boleh dimulai');
    }

    public function test_server_membaca_flag_segar_bukan_dari_halaman_lama(): void
    {
        [$so, $link] = $this->pesanan();
        // Flag berubah oleh sync SETELAH halaman dibuka: objek di tangan masih bersih.
        JubelioOrderLink::where('id', $link->id)->update(['last_status' => 'canceled']);

        $this->actingAs($this->admin())
            ->postJson(route('pos.fulfillment.proses-ajax', $so->id))
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['message' => 'DIBATALKAN DI MARKETPLACE — jangan diproses. Konfirmasi ke admin untuk pembatalan.']);
    }
}
