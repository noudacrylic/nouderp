<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockLedger;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Stok Jubelio yang diisi TANGAN dari panel Produk (sub-tab Custom).
 *
 * Barang custom tidak punya stok fisik yang bisa dihitung — SKU-nya cuma
 * wadah, barangnya baru dibuat setelah ada yang memesan. Karena itu stoknya di
 * marketplace sengaja TIDAK ikut sinkron otomatis; ia diisi sebesar yang
 * sanggup dikerjakan.
 *
 * Yang dijaga di sini dua hal yang merusak diam-diam kalau salah:
 *  1. Stok ERP tidak boleh ikut bergerak. Menuliskannya ke persediaan berarti
 *     mengarang barang yang belum ada, dan HPP serta laporan ikut karangan.
 *  2. Adjustment Jubelio bersifat DELTA. Baseline WAJIB diukur; menebaknya nol
 *     berarti seluruh angka ditambahkan ke saldo yang sudah ada → stok dobel →
 *     oversell.
 */
class StokJubelioManualTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function produkCustom(): Product
    {
        /*
         * Barisnya dipaksa ber-id 1, bukan lewat JubelioSetting::singleton().
         * `singleton()` memakai firstOrCreate(['id' => 1]) sementara `id` tidak
         * fillable, jadi baris barunya ikut auto-increment. Di tes kedua dan
         * seterusnya (AUTO_INCREMENT tidak mundur saat transaksi di-rollback)
         * ia melahirkan baris ber-id 2, 3, … yang tak pernah terbaca lagi oleh
         * pencarian id=1 — settingnya seolah kosong.
         */
        $setting = new JubelioSetting(['base_url' => JubelioSetting::DEFAULT_BASE_URL]);
        $setting->id = 1;
        $setting->default_location_id = 1;
        $setting->save();

        return Product::create([
            'sku'              => 'CS1',
            'name'             => 'Custom + Print 1',
            'is_active'        => true,
            'is_sellable'      => true,
            'sale_type'        => 'preorder',
            'made_to_order'    => true,
            'jubelio_item_id'  => 555,
        ]);
    }

    /**
     * Client palsu. $baseline = saldo yang "terbaca" di Jubelio; $adjustments
     * menampung apa yang benar-benar dikirim.
     */
    private function client(?float $baseline, array &$adjustments, bool $sukses = true): void
    {
        $client = Mockery::mock(JubelioClient::class);
        $client->shouldReceive('isReady')->andReturn(true);
        $client->shouldReceive('getItemAvailable')->andReturn($baseline);
        $client->shouldReceive('getDefaultBin')->andReturn(['success' => true, 'data' => ['bin_id' => 1]]);
        $client->shouldReceive('postAdjustment')
            ->andReturnUsing(function ($lokasi, $items, $catatan = null) use (&$adjustments, $sukses) {
                $adjustments[] = $items;

                return ['success' => $sukses, 'status' => $sukses ? 200 : 500, 'data' => [], 'error' => $sukses ? null : 'ditolak'];
            });

        $this->app->instance(JubelioClient::class, $client);
    }

    public function test_stok_erp_tidak_ikut_berubah(): void
    {
        $p = $this->produkCustom();

        $gudang = Warehouse::create(['name' => 'Gudang Jual', 'is_active' => true, 'is_sellable' => true]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => 2]);

        $adjustments = [];
        $this->client(0.0, $adjustments);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 10])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Inti fiturnya: yang berubah HANYA di Jubelio.
        $this->assertEquals(2, ProductStock::where('product_id', $p->id)->sum('qty_on_hand'));
        $this->assertSame(0, StockLedger::where('product_id', $p->id)->count());
    }

    /** Delta dihitung dari saldo yang DIUKUR, bukan dari nol. */
    public function test_delta_dihitung_dari_saldo_jubelio_yang_terbaca(): void
    {
        $p = $this->produkCustom();

        $adjustments = [];
        $this->client(4.0, $adjustments);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 10])
            ->assertOk();

        $this->assertCount(1, $adjustments);
        $this->assertEquals(6, $adjustments[0][0]['qty_in_base']);
    }

    /** Menurunkan stok juga sah — deltanya negatif. */
    public function test_stok_bisa_diturunkan(): void
    {
        $p = $this->produkCustom();

        $adjustments = [];
        $this->client(10.0, $adjustments);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 3])
            ->assertOk();

        $this->assertEquals(-7, $adjustments[0][0]['qty_in_base']);
    }

    /**
     * Stok Jubelio tak terbaca → BATAL, bukan menebak nol. Menebak berarti
     * seluruh angka ditambahkan ke saldo yang sudah ada.
     */
    public function test_saldo_tak_terbaca_membatalkan_pengiriman(): void
    {
        $p = $this->produkCustom();

        $adjustments = [];
        $this->client(null, $adjustments);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 10])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertCount(0, $adjustments);
    }

    /** Sudah sama dengan yang diminta → tidak ada adjustment yang ditembakkan. */
    public function test_tidak_menembak_apa_pun_kalau_sudah_sama(): void
    {
        $p = $this->produkCustom();

        $adjustments = [];
        $this->client(10.0, $adjustments);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 10])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertCount(0, $adjustments);
    }

    /**
     * Cache push ERP di-null-kan, TIDAK diisi angka manual: kalau diisi,
     * penyaring murah di pushProduct() salah menyimpulkan "tidak ada yang
     * berubah" dan melewatkan koreksi yang sebenarnya perlu.
     */
    public function test_cache_push_erp_dilupakan_bukan_diisi_angka_manual(): void
    {
        $p = $this->produkCustom();
        $p->forceFill(['jubelio_synced_qty' => 3])->save();

        $adjustments = [];
        $this->client(3.0, $adjustments);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 9])
            ->assertOk();

        $this->assertNull($p->fresh()->jubelio_synced_qty);
    }

    /**
     * Produk yang masih ikut sinkron otomatis tetap boleh diisi tangan, tapi
     * peringatannya harus terbaca — kalau tidak, admin menemukan sendiri
     * besok bahwa angkanya "berubah sendiri" karena ditimpa cron.
     */
    public function test_produk_yang_masih_sinkron_otomatis_diberi_peringatan(): void
    {
        $p = $this->produkCustom();
        $p->forceFill(['sync_to_jubelio' => true])->save();

        $adjustments = [];
        $this->client(0.0, $adjustments);

        $r = $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => 5])
            ->assertOk();

        $this->assertStringContainsString('ditimpa cron', $r->json('pesan'));
    }

    public function test_stok_negatif_ditolak(): void
    {
        $p = $this->produkCustom();

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.stok-jubelio', $p), ['stok' => -1])
            ->assertStatus(422);
    }
}
