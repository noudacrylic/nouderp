<?php

namespace Tests\Feature\Production;

use App\Core\Accounting\Account;
use App\Enums\AccountCodeEnum;
use App\Models\User;
use App\Modules\Production\Models\ProductionMaterialAddition;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jangkauan "Penambahan Bahan": task yang boleh dipilih & isi yang boleh disimpan.
 *
 * Dua aturan yang dijaga di sini:
 *   • Task yang masih ANTRE di langkah pertama (order 'confirmed', belum ada yang mulai)
 *     ikut muncul di dropdown — sebelumnya hanya langkah yang pendahulunya sudah selesai.
 *   • Penambahan boleh berisi BIAYA SAJA tanpa bahan baku sama sekali.
 */
class MaterialAdditionScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;
    private int $departmentId;
    private int $cashAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [AccountCodeEnum::WIP, 'Barang Dalam Proses'],
            [AccountCodeEnum::INVENTORY, 'Persediaan'],
        ] as [$code, $name]) {
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => 'asset',
                'normal_balance' => 'debit', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->cashAccountId = DB::table('accounts')->insertGetId([
            'code' => '1101', 'name' => 'Kas Kecil', 'type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => 1, 'is_cash_account' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Utama', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->departmentId = DB::table('production_departments')->insertGetId([
            'code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Order yang baru dikonfirmasi: semua langkah masih antre, belum ada yang mulai. */
    private function makeQueuedOrder(): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number'    => 'OP-ANTRE-' . uniqid(),
            'type'            => 'ready_stock',
            'warehouse_id'    => $this->warehouseId,
            'production_date' => now()->toDateString(),
            'planned_cycles'  => 1,
            'status'          => 'confirmed',
        ]);

        ProductionOrderStep::create([
            'production_order_id' => $order->id,
            'department_id'       => $this->departmentId,
            'step_number'         => 1,
            'name'                => 'Potong',
            'status'              => 'pending',
        ]);

        return $order->fresh('steps');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_langkah_pertama_yang_masih_antre_muncul_di_pilihan_task(): void
    {
        $order = $this->makeQueuedOrder();

        $response = $this->actingAs($this->admin())
            ->get(route('production.material-additions.create'));

        $response->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Antre');
    }

    public function test_bisa_simpan_biaya_saja_tanpa_bahan_baku(): void
    {
        $order = $this->makeQueuedOrder();
        $step  = $order->steps->first();

        $response = $this->actingAs($this->admin())
            ->post(route('production.material-additions.store'), [
                'production_order_step_id' => $step->id,
                'notes'                    => 'Beli baut di toko sebelah',
                // Baris bahan bawaan yang dibiarkan kosong tidak boleh bikin validasi gagal.
                'items'                    => [['product_id' => '', 'qty_requested' => '']],
                'costs'                    => [[
                    'description'     => 'Baut M3x15 20 pcs',
                    'amount'          => 25000,
                    'cash_account_id' => $this->cashAccountId,
                ]],
            ]);

        $response->assertSessionHasNoErrors();

        $addition = ProductionMaterialAddition::where('production_order_id', $order->id)->firstOrFail();
        $this->assertCount(0, $addition->items, 'Penambahan biaya-saja tidak boleh punya baris bahan.');
        $this->assertCount(1, $addition->costs);
        $this->assertEquals(25000, (float) $addition->costs->first()->amount);

        // Jurnal Dr. WIP / Cr. Kas tetap terbentuk walau tanpa bahan.
        $wipId = Account::where('code', AccountCodeEnum::WIP)->value('id');
        $wip = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.reference_type', 'production_cost_addition')
            ->where('journals.reference_id', $addition->id)
            ->where('journal_lines.account_id', $wipId)
            ->sum('journal_lines.debit');
        $this->assertEquals(25000, (float) $wip);
    }

    public function test_tanpa_bahan_dan_tanpa_biaya_ditolak(): void
    {
        $order = $this->makeQueuedOrder();
        $step  = $order->steps->first();

        $this->actingAs($this->admin())
            ->post(route('production.material-additions.store'), [
                'production_order_step_id' => $step->id,
                'items'                    => [['product_id' => '', 'qty_requested' => '']],
                'costs'                    => [],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('production_material_additions', 0);
    }

    public function test_order_dengan_penambahan_aktif_tidak_bisa_dibatalkan(): void
    {
        $order = $this->makeQueuedOrder();
        $step  = $order->steps->first();

        $this->actingAs($this->admin())
            ->post(route('production.material-additions.store'), [
                'production_order_step_id' => $step->id,
                'costs'                    => [[
                    'description'     => 'Ongkos kirim bahan',
                    'amount'          => 15000,
                    'cash_account_id' => $this->cashAccountId,
                ]],
            ])
            ->assertSessionHasNoErrors();

        // WIP dari penambahan tidak ikut dibalik oleh pembatalan order, jadi harus ditahan.
        $this->assertFalse($order->fresh()->canBeCancelled());

        $this->expectException(\Exception::class);
        app(\App\Modules\Production\Services\ProductionOrderService::class)->cancel($order->id);
    }
}
