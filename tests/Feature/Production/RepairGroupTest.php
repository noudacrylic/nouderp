<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\FifoService;
use App\Core\Inventory\StockLayer;
use App\Enums\AccountCodeEnum;
use App\Models\User;
use App\Modules\Production\Models\ProductionFinalization;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RepairGroup;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Production\Services\RepairGroupService;
use App\Modules\Production\Services\RepairQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kelompok Perbaikan + HPP OP Perbaikan.
 *
 * Aturan yang dijaga:
 *   • Kelompok memesan qty: satu unit tak bisa masuk dua kelompok; batal = kembali ke antrean.
 *   • 1 kelompok = 1 OP. OP batal → kelompok terbuka lagi; OP final → kelompok selesai.
 *   • HPP awal tiap SKU TIDAK dilebur. Biaya perbaikan dibagi SEBANDING HPP awal, jadi semua
 *     SKU naik dengan persentase sama. Angka uji = contoh yang disepakati dengan user:
 *     Tempat Brosur A6 1 unit @5.000 & Rak Bolpoin 8 unit @19.037, biaya perbaikan 45.000
 *     → 6.430 dan 24.483 per unit.
 *   • Unit gagal diperbaiki menanggung nilai per unit yang sama, dibebankan ke 6105, tak masuk stok.
 */
class RepairGroupTest extends TestCase
{
    use RefreshDatabase;

    private int $gudangPerbaikan;
    private int $gudangJual;
    private int $a6;
    private int $rak;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [AccountCodeEnum::WIP, 'Barang Dalam Proses', 'asset', 'debit'],
            [AccountCodeEnum::INVENTORY, 'Persediaan', 'asset', 'debit'],
            [AccountCodeEnum::INVENTORY_REPAIR, 'Persediaan Perbaikan', 'asset', 'debit'],
            [AccountCodeEnum::SALES_LOSS, 'Beban Kerugian Retur', 'expense', 'debit'],
            ['1101', 'Kas', 'asset', 'debit'],
        ] as [$code, $name, $type, $nb]) {
            DB::table('accounts')->updateOrInsert(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $nb, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->gudangJual = DB::table('warehouses')->insertGetId([
            'name' => 'Utama', 'is_active' => 1, 'is_sellable' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->gudangPerbaikan = DB::table('warehouses')->where('is_repair', true)->value('id')
            ?? DB::table('warehouses')->insertGetId([
                'name' => 'Gudang Perbaikan', 'is_active' => 1, 'is_sellable' => 0, 'is_repair' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $this->a6  = $this->product('TBKD-A6-T1-M4', 'Tempat Brosur A6 Dinding Knock Down');
        $this->rak = $this->product('RP-2x5', 'Rak Bolpoin 10 Kotak 2 Susun');

        $fifo = app(FifoService::class);
        $fifo->stockIn($this->a6, $this->gudangPerbaikan, 'sales_return', 'SR/TES/1', 1, 5000, 1);
        $fifo->stockIn($this->rak, $this->gudangPerbaikan, 'sales_return', 'SR/TES/2', 8, 19037, 2);

        app(\App\Core\Period\PeriodService::class)->ensureOpen(now());
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    private function product(string $sku, string $name): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $name, 'base_unit' => 'pcs', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kelompokSvc(): RepairGroupService
    {
        return app(RepairGroupService::class);
    }

    private function svc(): ProductionOrderService
    {
        return app(ProductionOrderService::class);
    }

    /** Kelompok → OP → konfirmasi (barang keluar Gudang Perbaikan) → biaya perbaikan → siap final. */
    private function opSiapFinal(float $biayaPerbaikan = 45000): ProductionOrder
    {
        $g  = $this->kelompokSvc()->create('Brosur & Rak', [$this->a6 => 1, $this->rak => 8]);
        $op = $this->svc()->create([
            'type' => 'perbaikan', 'repair_group_id' => $g->id,
            'warehouse_id' => $this->gudangJual, 'production_date' => now()->toDateString(),
            'score_type' => 'priority', 'priority_level' => 'medium',
        ]);
        $this->svc()->confirm($op->id);

        if ($biayaPerbaikan > 0) {
            $this->tambahWip($op, $biayaPerbaikan);
        }

        $op->update(['status' => 'completed']);

        return $op->refresh()->load('outputs');
    }

    /** Biaya perbaikan masuk WIP seperti jurnal Biaya Produksi (Dr WIP / Cr Kas). */
    private function tambahWip(ProductionOrder $op, float $amount): void
    {
        $periodId = DB::table('accounting_periods')->where('year', now()->year)->where('month', now()->month)->value('id');
        $jid = DB::table('journals')->insertGetId([
            'journal_number' => 'JRN-' . uniqid(), 'date' => now()->toDateString(),
            'reference_type' => 'production_order_cost', 'reference_id' => $op->id,
            'description' => 'Biaya perbaikan uji', 'period_id' => $periodId, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_id' => $jid, 'account_id' => $this->acc(AccountCodeEnum::WIP), 'debit' => $amount, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_id' => $jid, 'account_id' => $this->acc('1101'), 'debit' => 0, 'credit' => $amount, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function acc(string $code): int
    {
        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    /** Saldo (debit − kredit) akun dari jurnal POSTED — cara AccountBalanceService menghitung. */
    private function saldo(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->where('l.account_id', $this->acc($code))
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as s')->value('s');
    }

    private function unitCost(int $productId): float
    {
        $l = StockLayer::where('product_id', $productId)->where('warehouse_id', $this->gudangJual)->get();
        return $l->sum('qty_in') > 0 ? (float) $l->sum(fn ($x) => $x->qty_in * $x->unit_cost) / (float) $l->sum('qty_in') : 0;
    }

    private function outputs(ProductionOrder $op, array $ok, array $gagal = []): array
    {
        return $op->outputs->values()->map(fn ($o) => [
            'output_id'    => $o->id,
            'qty_produced' => $ok[$o->product_id] ?? 0,
            'qty_failed'   => $gagal[$o->product_id] ?? 0,
        ])->all();
    }

    public function test_kelompok_memesan_qty_dan_batal_mengembalikannya(): void
    {
        $g = $this->kelompokSvc()->create('Rak saja', [$this->rak => 5]);

        $this->assertEqualsWithDelta(3, app(RepairQueueService::class)->tersedia()[$this->rak], 0.001);

        try {
            $this->kelompokSvc()->create('Rak lagi', [$this->rak => 4]);
            $this->fail('Unit yang sudah dipesan kelompok lain tidak boleh dikelompokkan lagi.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $this->kelompokSvc()->cancel($g);
        $this->assertEqualsWithDelta(8, app(RepairQueueService::class)->tersedia()[$this->rak], 0.001);
    }

    public function test_op_dari_kelompok_mengunci_kelompok_dan_batal_membukanya_lagi(): void
    {
        $g  = $this->kelompokSvc()->create('Brosur', [$this->a6 => 1]);
        $op = $this->svc()->create([
            'type' => 'perbaikan', 'repair_group_id' => $g->id,
            'warehouse_id' => $this->gudangJual, 'production_date' => now()->toDateString(),
        ]);

        $this->assertSame(RepairGroup::DI_OP, $g->fresh()->status);
        $this->assertSame($op->id, $g->fresh()->production_order_id);
        $this->assertCount(1, $op->materials);

        try {
            $this->kelompokSvc()->update($g->fresh(), 'Ubah', [$this->a6 => 1]);
            $this->fail('Kelompok yang sudah jadi OP tidak boleh diubah.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Terbuka', $e->getMessage());
        }

        $this->svc()->cancel($op->id);
        $this->assertSame(RepairGroup::TERBUKA, $g->fresh()->status);
        $this->assertNull($g->fresh()->production_order_id);
    }

    public function test_hpp_awal_tidak_dilebur_biaya_perbaikan_dibagi_sebanding(): void
    {
        $op = $this->opSiapFinal();

        $this->svc()->finalize($op->id, $this->outputs($op, [$this->a6 => 1, $this->rak => 8]));

        // Total HPP awal 157.296; biaya 45.000 → semua naik 28,61%.
        $this->assertEqualsWithDelta(6430.4, $this->unitCost($this->a6), 0.5);
        $this->assertEqualsWithDelta(24483.1, $this->unitCost($this->rak), 0.5);

        $this->assertEqualsWithDelta(0, $this->saldo(AccountCodeEnum::WIP), 0.01, 'WIP OP perbaikan harus nol');
        $this->assertEqualsWithDelta(0, $this->saldo(AccountCodeEnum::SALES_LOSS), 0.01);
        $this->assertSame(RepairGroup::SELESAI, RepairGroup::where('production_order_id', $op->id)->value('status'));
    }

    public function test_unit_gagal_dibebankan_dan_tidak_menaikkan_hpp_unit_berhasil(): void
    {
        $op = $this->opSiapFinal();

        $this->svc()->finalize($op->id, $this->outputs($op, [$this->a6 => 1, $this->rak => 7], [$this->rak => 1]));

        $this->assertEqualsWithDelta(24483.1, $this->unitCost($this->rak), 0.5, 'unit berhasil tetap HPP normal');
        $this->assertEqualsWithDelta(7, StockLayer::where('product_id', $this->rak)->where('warehouse_id', $this->gudangJual)->sum('qty_in'), 0.001);
        $this->assertEqualsWithDelta(24483.1, $this->saldo(AccountCodeEnum::SALES_LOSS), 0.5, '1 unit gagal → 6105');
        $this->assertEqualsWithDelta(0, $this->saldo(AccountCodeEnum::WIP), 0.01);

        $out = $op->outputs()->where('product_id', $this->rak)->first();
        $this->assertEqualsWithDelta(7, $out->qty_produced, 0.001);
        $this->assertEqualsWithDelta(1, $out->qty_failed, 0.001);
    }

    public function test_penutup_wajib_menuntaskan_semua_unit(): void
    {
        $op = $this->opSiapFinal();

        $this->expectExceptionMessage('harus 8 unit');
        $this->svc()->finalize($op->id, $this->outputs($op, [$this->a6 => 1, $this->rak => 6], [$this->rak => 1]));
    }

    public function test_sebagian_lalu_penutup_tetap_sebanding_dan_wip_nol(): void
    {
        $op = $this->opSiapFinal();
        $op->update(['status' => 'in_progress']);
        DB::table('production_order_steps')->insert([
            'production_order_id' => $op->id, 'step_number' => 1, 'name' => 'Perbaikan',
            'status' => 'in_progress', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->svc()->finalizePartial($op->id, $this->outputs($op->refresh()->load('outputs'), [$this->rak => 3], [$this->rak => 1]));
        $this->assertEqualsWithDelta(24483.1, $this->unitCost($this->rak), 0.5);

        $op->update(['status' => 'completed']);
        $this->svc()->finalize($op->id, $this->outputs($op->refresh()->load('outputs'), [$this->a6 => 1, $this->rak => 4]));

        $this->assertEqualsWithDelta(6430.4, $this->unitCost($this->a6), 0.5);
        $this->assertEqualsWithDelta(24483.1, $this->unitCost($this->rak), 0.5);
        $this->assertEqualsWithDelta(0, $this->saldo(AccountCodeEnum::WIP), 0.01);
    }

    public function test_batal_batch_membalik_beban_gagal_dan_kelompok_kembali_di_op(): void
    {
        $op = $this->opSiapFinal();
        $this->svc()->finalize($op->id, $this->outputs($op, [$this->a6 => 1, $this->rak => 7], [$this->rak => 1]));

        $wipSetelahFinal = $this->saldo(AccountCodeEnum::WIP);
        $batch = ProductionFinalization::where('production_order_id', $op->id)->first();
        $this->svc()->voidBatch($batch->id);

        // Setelah dibatalkan, seluruh nilai kembali ke WIP (HPP awal 157.296 + biaya 45.000).
        $this->assertEqualsWithDelta(0, $this->saldo(AccountCodeEnum::SALES_LOSS), 0.5);
        $this->assertEqualsWithDelta($wipSetelahFinal + 202296, $this->saldo(AccountCodeEnum::WIP), 0.5);
        $this->assertEqualsWithDelta(0, $op->outputs()->where('product_id', $this->rak)->value('qty_failed'), 0.001);
        $this->assertSame(RepairGroup::DI_OP, RepairGroup::where('production_order_id', $op->id)->value('status'));
    }

    public function test_halaman_kelompok_dan_form_op_terbuka(): void
    {
        $g = $this->kelompokSvc()->create('Brosur', [$this->a6 => 1]);

        $this->get(route('production.perbaikan.index', ['tab' => 'kelompok']))->assertOk()->assertSee($g->number);
        $this->get(route('production.perbaikan.kelompok.edit', $g->id))->assertOk()->assertSee('Ubah Kelompok');
        // Daftar kelompok dikirim ke form sebagai JSON (@json meng-escape '/').
        $this->get(route('production.orders.create', ['type' => 'perbaikan', 'kelompok' => $g->id]))->assertOk()
            ->assertSee(str_replace('/', '\/', $g->number), false);
        $this->get(route('production.perbaikan.index'))->assertOk()->assertSee('Buat Kelompok');
    }
}
