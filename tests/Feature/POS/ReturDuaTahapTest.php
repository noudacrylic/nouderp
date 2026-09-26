<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retur dikerjakan di Pemrosesan Pesanan, dalam DUA tahap saja.
 *
 * Tahap lama `diproses` dihapus karena ia tak pernah menjawab pertanyaan apa pun:
 * "baru" dan "diproses" sama-sama berarti retur yang belum selesai. Yang benar-benar
 * beda nasibnya adalah retur yang sedang DISENGKETAKAN ke marketplace — itu yang
 * kini punya tempat sendiri, dan hanya dimasuki lewat tombol.
 */
class ReturDuaTahapTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function pesanan(bool $marketplace = false): SalesOrder
    {
        $cust = Customer::create([
            'code'           => 'CUST-' . uniqid(),
            'name'           => $marketplace ? 'Shopee Official' : 'Toko Budi',
            'is_marketplace' => $marketplace,
            'is_active'      => true,
        ]);

        return SalesOrder::create([
            'order_number'         => 'SO-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => now()->subDays(20)->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'subtotal'             => 100000,
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
            'delivery_method'      => 'kurir',
        ]);
    }

    private function retur(SalesOrder $so, string $stage = 'baru', string $status = 'draft'): SalesReturn
    {
        return SalesReturn::create([
            'return_number'  => 'SR-' . uniqid(),
            'customer_id'    => $so->customer_id,
            'sales_order_id' => $so->id,
            'return_date'    => now()->toDateString(),
            'grand_total'    => 50000,
            'status'         => $status,
            'stage'          => $stage,
        ]);
    }

    private function nomorRetur(string $tahap): array
    {
        return app(FulfillmentReadinessService::class)
            ->returRows($tahap)
            ->pluck('retur_number')
            ->filter()
            ->all();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /* --------------------------------------------------------------- tahap */

    public function test_hanya_dua_tahap_yang_menuntut_pekerjaan(): void
    {
        $this->assertSame(['baru', 'banding'], SalesReturn::STAGES_AKTIF);
        $this->assertArrayNotHasKey('diproses', SalesReturn::STAGES);
    }

    public function test_tiap_tahap_hanya_memuat_returnya_sendiri(): void
    {
        $baru    = $this->retur($this->pesanan(), 'baru');
        $banding = $this->retur($this->pesanan(), 'banding');

        $this->assertSame([$baru->return_number], $this->nomorRetur('baru'));
        $this->assertSame([$banding->return_number], $this->nomorRetur('banding'));
    }

    /** Retur yang sudah diselesaikan keluar dari kedua tab — tempatnya di "Selesai". */
    public function test_retur_yang_sudah_diselesaikan_tidak_muncul_lagi(): void
    {
        $selesai = $this->retur($this->pesanan(), 'selesai', 'posted');

        $this->assertNotContains($selesai->return_number, $this->nomorRetur('baru'));
        $this->assertNotContains($selesai->return_number, $this->nomorRetur('banding'));
    }

    /**
     * Retur non-marketplace ikut tampil di layar yang sama.
     *
     * Tab lama menyaring pesanan lewat tautan Jubelio, jadi retur dari pembeli
     * toko/web tidak akan pernah muncul di sana sama sekali — CS harus pindah ke
     * modul Sales untuk mengerjakannya. Itu yang dicabut.
     */
    public function test_retur_non_marketplace_ikut_tampil(): void
    {
        $retur = $this->retur($this->pesanan(marketplace: false), 'baru');

        $this->assertContains($retur->return_number, $this->nomorRetur('baru'));
    }

    /**
     * Pesanan yang ditandai diretur marketplace tapi dokumennya belum terbentuk
     * tetap terlihat. Kalau tidak, ia lenyap dari semua layar — dan pekerjaan yang
     * tidak punya tempat adalah pekerjaan yang tidak dikerjakan.
     */
    public function test_pesanan_diretur_tanpa_dokumen_tetap_terlihat(): void
    {
        $so = $this->pesanan(marketplace: true);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 6001,
            'jubelio_salesorder_no' => 'TP-6001',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'returned',
        ]);

        $baris = app(FulfillmentReadinessService::class)
            ->returRows('baru')
            ->firstWhere('number', $so->order_number);

        $this->assertNotNull($baris, 'pesanan diretur tanpa dokumen harus tetap terlihat');
        $this->assertNull($baris['retur_id']);
        $this->assertSame(1, app(FulfillmentReadinessService::class)->returCounts()['baru']);
    }

    /**
     * Terbaru di atas, dan itu berlaku LINTAS SUMBER.
     *
     * Dua sumber mengalir ke daftar ini — dokumen retur dan pesanan yang ditandai
     * diretur tanpa dokumen. Menyambungnya begitu saja menaruh seluruh baris
     * tanpa-dokumen di bawah apa pun tanggalnya, jadi retur yang masuk pagi ini
     * terkubur di belakang puluhan kasus dua bulan lalu.
     */
    public function test_daftar_terurut_dari_yang_terbaru_lintas_sumber(): void
    {
        $lama = $this->retur($this->pesanan(), 'baru');
        $lama->forceFill(['created_at' => now()->subDays(30)])->save();

        $baru = $this->retur($this->pesanan(), 'baru');
        $baru->forceFill(['created_at' => now()->subDay()])->save();

        // Pesanan tanpa dokumen, tanggalnya di ANTARA keduanya.
        $so = $this->pesanan(marketplace: true);
        $so->forceFill(['order_date' => now()->subDays(10)->toDateString()])->save();

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 6002,
            'jubelio_salesorder_no' => 'TP-6002',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'returned',
        ]);

        $urut = app(FulfillmentReadinessService::class)
            ->returRows('baru')
            ->map(fn ($r) => $r['retur_number'] ?? $r['number'])
            ->all();

        $this->assertSame(
            [$baru->return_number, $so->order_number, $lama->return_number],
            $urut,
            'baris tanpa dokumen harus duduk sesuai tanggalnya, bukan dibuang ke bawah'
        );
    }

    /* ------------------------------------------------------- pindah tahap */

    public function test_tombol_memindahkan_retur_ke_banding_dan_kembali(): void
    {
        $retur = $this->retur($this->pesanan(), 'baru');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('pos.fulfillment.retur-tahap', $retur->id), ['tahap' => 'banding'])
            ->assertRedirect();

        $this->assertSame('banding', $retur->fresh()->stage);

        $this->actingAs($admin)
            ->post(route('pos.fulfillment.retur-tahap', $retur->id), ['tahap' => 'baru'])
            ->assertRedirect();

        $this->assertSame('baru', $retur->fresh()->stage);
    }

    /** Retur yang sudah diselesaikan tidak boleh ditarik mundur ke antrean kerja. */
    public function test_retur_yang_sudah_diselesaikan_tidak_bisa_dipindahkan(): void
    {
        $retur = $this->retur($this->pesanan(), 'selesai', 'posted');

        $this->actingAs($this->admin())
            ->post(route('pos.fulfillment.retur-tahap', $retur->id), ['tahap' => 'banding'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('selesai', $retur->fresh()->stage);
    }

    /* ------------------------------------------------------------- layar */

    public function test_layar_retur_menampilkan_dua_subtab(): void
    {
        $retur = $this->retur($this->pesanan(), 'baru');

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.retur'))
            ->assertOk()
            ->assertSee('Retur Baru')
            ->assertSee('Banding')
            ->assertSee($retur->return_number);
    }

    public function test_layar_banding_hanya_memuat_yang_dibanding(): void
    {
        $baru    = $this->retur($this->pesanan(), 'baru');
        $banding = $this->retur($this->pesanan(), 'banding');

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.retur', ['tahap' => 'banding']))
            ->assertOk()
            ->assertSee($banding->return_number)
            ->assertDontSee($baru->return_number);
    }

    /** Sales > Retur jadi riwayat polos: tanpa tab tahap, menunjuk balik ke layar kerjanya. */
    public function test_sales_retur_jadi_daftar_polos(): void
    {
        $retur = $this->retur($this->pesanan(), 'banding');

        $this->actingAs($this->admin())
            ->get(route('sales.returns.index'))
            ->assertOk()
            ->assertSee($retur->return_number)
            ->assertSee('Pemrosesan Pesanan');
    }
}
