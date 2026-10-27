<?php

namespace Tests\Feature\Inventory;

use App\Core\Inventory\FifoService;
use App\Core\Inventory\StockLayer;
use App\Models\InventoryCostLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lindungi perbaikan FIFO ronde-1:
 *  - SEDANG-5: consume & moveLayer urut created_at lalu id (deterministik saat timestamp sama).
 *  - SEDANG-6: toleransi epsilon (sisa float micro tak dianggap "stok tidak cukup").
 */
class FifoServiceTest extends TestCase
{
    use RefreshDatabase;

    private FifoService $fifo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fifo = new FifoService();
    }

    private function layer(float $qty, float $cost, ?string $createdAt = null, int $product = 1, int $wh = 1): StockLayer
    {
        $l = StockLayer::create([
            'product_id'    => $product,
            'warehouse_id'  => $wh,
            'qty_in'        => $qty,
            'qty_remaining' => $qty,
            'unit_cost'     => $cost,
            'source_type'   => 'purchase',
            'source_id'     => 0,
        ]);
        if ($createdAt) {
            // set langsung agar urutan created_at terkontrol
            StockLayer::where('id', $l->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        }
        return $l->refresh();
    }

    public function test_consume_mengambil_layer_tertua_dulu_fifo(): void
    {
        $a = $this->layer(10, 100, '2026-01-01 00:00:00'); // tertua, murah
        $b = $this->layer(10, 200, '2026-02-01 00:00:00'); // termuda, mahal

        $cogs = $this->fifo->consume(1, 1, 5, 'sales', 99);

        $this->assertEqualsWithDelta(500.0, $cogs, 0.001, 'COGS harus dari layer tertua (5 x 100)');
        $this->assertEqualsWithDelta(5.0, $a->refresh()->qty_remaining, 0.001);
        $this->assertEqualsWithDelta(10.0, $b->refresh()->qty_remaining, 0.001, 'layer termuda belum tersentuh');
    }

    /** OP custom ber-SO tanpa memerlukan baris SO (FK dimatikan sebentar). */
    private function opUntukSo(int $soId, ?int $mergedInto = null): int
    {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $id = \Illuminate\Support\Facades\DB::table('production_orders')->insertGetId([
            'order_number' => 'OP-TES-' . uniqid(), 'type' => 'custom', 'sales_order_id' => $soId,
            'merged_into_id' => $mergedInto, 'warehouse_id' => 1, 'status' => 'finalized',
            'production_date' => '2026-10-20', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
        return $id;
    }

    public function test_khusus_pesanan_hanya_mengambil_lapisan_op_milik_so(): void
    {
        // SO 7920 (Adi Mulyadi): CS3 hasil OP/2026/10/00083 Rp1,61 jt, tapi Surat Jalan
        // mengambil lapisan tertua milik pesanan lain Rp17 rb.
        $lain  = $this->layer(20, 17140.74, '2026-09-26 00:00:00');
        $milik = $this->layer(1, 1611076.76, '2026-10-20 00:00:00');
        $milik->update(['source_type' => 'production_order', 'source_id' => $this->opUntukSo(7920)]);

        $this->assertEqualsWithDelta(1.0, $this->fifo->sisaMilikSo(1, 1, 7920), 0.001);

        $cogs = $this->fifo->consume(1, 1, 1, 'sales', 99, 7920, true);

        $this->assertEqualsWithDelta(1611076.76, $cogs, 0.01);
        $this->assertEqualsWithDelta(20.0, $lain->refresh()->qty_remaining, 0.001, 'Lapisan pesanan lain tak tersentuh.');

        // Hasil OP-nya sudah habis → TIDAK boleh mengambil stok pesanan lain.
        $this->expectException(\Exception::class);
        $this->fifo->consume(1, 1, 1, 'sales', 100, 7920, true);
    }

    public function test_khusus_pesanan_ikut_lapisan_op_induk_bila_digabung(): void
    {
        $induk = $this->opUntukSo(500);
        $this->opUntukSo(7920, $induk);
        $l = $this->layer(2, 50000, '2026-10-20 00:00:00');
        $l->update(['source_type' => 'production_order', 'source_id' => $induk]);
        $this->layer(5, 1000, '2026-01-01 00:00:00');

        $this->assertEqualsWithDelta(50000.0, $this->fifo->consume(1, 1, 1, 'sales', 99, 7920, true), 0.01);
    }

    public function test_khusus_pesanan_tanpa_op_sama_sekali_jatuh_ke_fifo(): void
    {
        $this->layer(5, 1000, '2026-01-01 00:00:00');
        $this->layer(5, 9000, '2026-02-01 00:00:00');

        $this->assertNull($this->fifo->sisaMilikSo(1, 1, 4242));
        $this->assertEqualsWithDelta(1000.0, $this->fifo->consume(1, 1, 1, 'sales', 99, 4242, true), 0.01);
    }

    public function test_produk_stok_bersama_tetap_fifo_murni_walau_ada_op_milik_so(): void
    {
        $this->layer(5, 1000, '2026-01-01 00:00:00');
        $milik = $this->layer(1, 9000, '2026-02-01 00:00:00');
        $milik->update(['source_type' => 'production_order', 'source_id' => $this->opUntukSo(7920)]);

        $this->assertEqualsWithDelta(1000.0, $this->fifo->consume(1, 1, 1, 'sales', 99, 7920), 0.01);
    }

    public function test_consume_lintas_layer_cogs_berbobot(): void
    {
        $a = $this->layer(10, 100, '2026-01-01 00:00:00');
        $b = $this->layer(10, 200, '2026-02-01 00:00:00');

        $cogs = $this->fifo->consume(1, 1, 12, 'sales', 99); // 10x100 + 2x200

        $this->assertEqualsWithDelta(1400.0, $cogs, 0.001);
        $this->assertEqualsWithDelta(0.0, $a->refresh()->qty_remaining, 0.001);
        $this->assertEqualsWithDelta(8.0, $b->refresh()->qty_remaining, 0.001);
    }

    public function test_consume_tiebreak_pakai_id_saat_created_at_sama(): void
    {
        // Timestamp identik → urutan harus deterministik by id (SEDANG-5).
        $ts = '2026-03-01 12:00:00';
        $a = $this->layer(5, 100, $ts); // id lebih kecil (dibuat dulu)
        $b = $this->layer(5, 999, $ts); // id lebih besar

        $cogs = $this->fifo->consume(1, 1, 5, 'sales', 99);

        $this->assertEqualsWithDelta(500.0, $cogs, 0.001, 'harus konsumsi id terkecil dulu (5 x 100)');
        $this->assertEqualsWithDelta(0.0, $a->refresh()->qty_remaining, 0.001);
        $this->assertEqualsWithDelta(5.0, $b->refresh()->qty_remaining, 0.001);
    }

    public function test_consume_mencatat_qty_out_di_inventory_cost_layer(): void
    {
        $this->layer(10, 100, '2026-01-01 00:00:00');

        $this->fifo->consume(1, 1, 4, 'sales', 77);

        $out = InventoryCostLayer::where('reference_type', 'sales')
            ->where('reference_id', 77)->where('qty_out', '>', 0)->sum('qty_out');
        $this->assertEqualsWithDelta(4.0, (float) $out, 0.001, 'jejak konsumsi qty_out harus tercatat');
    }

    public function test_kekurangan_micro_float_diserap_epsilon(): void
    {
        // Tersedia 9.999995, diminta 10.0 → kekurangan 5e-6 (< epsilon 1e-5).
        // Kode lama (remaining > 0) MELEMPAR; kode baru (> 0.00001) LOLOS.
        $this->layer(9.999995, 100, '2026-01-01 00:00:00');

        $cogs = $this->fifo->consume(1, 1, 10.0, 'sales', 99);

        $this->assertGreaterThan(0, $cogs, 'kekurangan micro-float tak boleh dianggap stok tidak cukup');
    }

    public function test_kekurangan_nyata_tetap_melempar(): void
    {
        $this->layer(9.99, 100, '2026-01-01 00:00:00'); // kurang 0.01 (> epsilon)

        $this->expectException(\Exception::class);
        $this->fifo->consume(1, 1, 10.0, 'sales', 99);
    }

    public function test_movelayer_urut_fifo_dan_pindah_gudang(): void
    {
        $a = $this->layer(10, 100, '2026-01-01 00:00:00', product: 1, wh: 1);
        $b = $this->layer(10, 200, '2026-02-01 00:00:00', product: 1, wh: 1);

        $this->fifo->moveLayer(1, 1, 2, 12); // pindah 12 dari gudang 1 ke 2

        $this->assertEqualsWithDelta(0.0, $a->refresh()->qty_remaining, 0.001, 'tertua dipindah dulu');
        $this->assertEqualsWithDelta(8.0, $b->refresh()->qty_remaining, 0.001);

        $moved = StockLayer::where('warehouse_id', 2)->where('source_type', 'transfer')->get();
        $this->assertEqualsWithDelta(12.0, (float) $moved->sum('qty_remaining'), 0.001);
        // cost layer asli dipertahankan (10@100 + 2@200)
        $this->assertEqualsWithDelta(1400.0, (float) $moved->sum(fn ($m) => $m->qty_remaining * $m->unit_cost), 0.001);
    }
}
