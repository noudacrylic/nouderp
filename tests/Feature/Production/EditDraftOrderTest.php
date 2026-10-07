<?php

namespace Tests\Feature\Production;

use App\Enums\AccountCodeEnum;
use App\Models\User;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OP yang masih Draft boleh diedit (tak perlu batal lalu buat ulang).
 * Biaya produksi sudah dijurnal saat OP dibuat → saat biaya diubah, jurnal lama di-void
 * dan diposting ulang; edit tanpa ubah biaya tidak boleh menyentuh jurnal.
 */
class EditDraftOrderTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrderService $service;
    private int $warehouseId;
    private int $cashId;
    private int $bahanA;
    private int $bahanB;
    private int $hasil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductionOrderService::class);

        foreach ([
            [AccountCodeEnum::WIP, 'Barang Dalam Proses'],
            [AccountCodeEnum::INVENTORY, 'Persediaan'],
            ['1101', 'Kas'],
        ] as [$code, $name]) {
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => 'asset',
                'normal_balance' => 'debit', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->cashId = (int) DB::table('accounts')->where('code', '1101')->value('id');

        $this->warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Utama', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->bahanA = $this->product('LBR-3MM', 'Lembaran 3mm');
        $this->bahanB = $this->product('LBR-5MM', 'Lembaran 5mm');
        $this->hasil  = $this->product('AM-01', 'Akrilik Menu');
    }

    private function product(string $sku, string $name): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $name, 'base_unit' => 'pcs',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'type'            => 'ready_stock',
            'warehouse_id'    => $this->warehouseId,
            'production_date' => now()->toDateString(),
            'planned_cycles'  => 1,
            'materials'       => [['product_id' => $this->bahanA, 'qty_required' => 2, 'unit' => 'pcs']],
            'outputs'         => [['product_id' => $this->hasil, 'qty_planned' => 5, 'output_type' => 'main', 'percentage' => 100]],
            'steps'           => [['name' => 'CNC', 'department_id' => null]],
            'costs'           => [['description' => 'Baut', 'amount' => 10000, 'cash_account_id' => $this->cashId]],
        ], $override);
    }

    private function activeCostJournals(int $orderId)
    {
        return DB::table('journals')->where('reference_type', 'production_order_cost')
            ->where('reference_id', $orderId)->where('status', '!=', 'void')->get();
    }

    public function test_edit_draft_mengganti_material_output_langkah(): void
    {
        $order = $this->service->create($this->payload());

        $this->service->update($order->id, $this->payload([
            'notes'     => 'salah bahan',
            'materials' => [['product_id' => $this->bahanB, 'qty_required' => 3, 'unit' => 'pcs']],
            'outputs'   => [['product_id' => $this->hasil, 'qty_planned' => 7, 'output_type' => 'main', 'percentage' => 100]],
            'steps'     => [['name' => 'CNC'], ['name' => 'Assembling']],
            'costs'     => [['description' => 'Baut', 'amount' => 25000, 'cash_account_id' => $this->cashId]],
        ]));

        $order->refresh();
        $this->assertSame('draft', $order->status);
        $this->assertSame('salah bahan', $order->notes);
        $this->assertSame([$this->bahanB], $order->materials()->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEqualsWithDelta(7, (float) $order->outputs()->value('qty_planned'), 1e-6);
        $this->assertSame(['CNC', 'Assembling'], $order->steps()->orderBy('step_number')->pluck('name')->all());
        $this->assertEqualsWithDelta(25000, (float) $order->costs()->sum('amount'), 0.01);
    }

    public function test_draft_belum_berjurnal_biaya_dijurnal_saat_konfirmasi(): void
    {
        $order = $this->service->create($this->payload());

        // Draft: baris biaya tersimpan, tapi belum ada jurnal sama sekali.
        $this->assertSame(1, $order->costs()->count());
        $this->assertSame(0, DB::table('journals')->where('reference_id', $order->id)
            ->where('reference_type', 'like', 'production_order%')->count());

        $this->service->confirm($order->id);

        $active = $this->activeCostJournals($order->id);
        $this->assertCount(1, $active);
        $this->assertEqualsWithDelta(10000, (float) DB::table('journal_lines')
            ->where('journal_id', $active->first()->id)->sum('debit'), 0.01);

        // Batal setelah konfirmasi (belum dikerjakan) → jurnal pembalik, jurnal asli tetap.
        $this->service->cancel($order->id);
        $this->assertCostReversed($order->id, 10000);
    }

    public function test_jurnal_biaya_draft_versi_lama_tidak_dobel_dan_dibalik_saat_batal(): void
    {
        $order = $this->service->create($this->payload());
        // Tiru OP draft lama: biayanya terlanjur dijurnal saat dibuat.
        $this->invokePrivate('postCostJournal', $order);
        $this->assertCount(1, $this->activeCostJournals($order->id));

        // Konfirmasi tidak memposting jurnal biaya kedua.
        $draft2 = $this->service->create($this->payload());
        $this->invokePrivate('postCostJournal', $draft2);
        $this->service->confirm($draft2->id);
        $this->assertCount(1, $this->activeCostJournals($draft2->id));

        // Batal draft lama → jurnal biayanya dibalik; batal kedua kali tak memasang pembalik lagi.
        $this->service->cancel($order->id);
        $this->assertCostReversed($order->id, 10000);
    }

    public function test_perintah_perbaikan_membalik_jurnal_biaya_op_batal_lama(): void
    {
        // Tiru data lama: OP batal yang jurnal biayanya tidak pernah dibalik.
        $order = $this->service->create($this->payload());
        $this->invokePrivate('postCostJournal', $order);
        $order->update(['status' => 'cancelled']);

        $this->artisan('production:fix-cost-journals', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('journals')->where('reference_type', 'production_order_cost_cancel')->count());

        $this->artisan('production:fix-cost-journals')->assertSuccessful();
        $this->assertCostReversed($order->id, 10000);

        // Idempoten: jalan lagi tidak memasang pembalik kedua.
        $this->artisan('production:fix-cost-journals')->assertSuccessful();
        $this->assertSame(1, DB::table('journals')->where('reference_type', 'production_order_cost_cancel')->count());
    }

    private function assertCostReversed(int $orderId, float $amount): void
    {
        $this->assertCount(1, $this->activeCostJournals($orderId));
        $rev = DB::table('journals')->where('reference_type', 'production_order_cost_cancel')
            ->where('reference_id', $orderId)->where('status', '!=', 'void')->get();
        $this->assertCount(1, $rev);
        $this->assertEqualsWithDelta($amount, (float) DB::table('journal_lines')
            ->where('journal_id', $rev->first()->id)->where('account_id', $this->cashId)->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(0, $this->service->wipBreakdown($orderId)['cost'], 0.01);
    }

    private function invokePrivate(string $method, ProductionOrder $order): void
    {
        $m = new \ReflectionMethod($this->service, $method);
        $m->setAccessible(true);
        $m->invoke($this->service, $order);
    }

    public function test_order_yang_sudah_dikonfirmasi_tidak_bisa_diedit(): void
    {
        $order = $this->service->create($this->payload(['costs' => []]));
        $order->update(['status' => 'confirmed']);

        $this->expectExceptionMessage('Draft');
        $this->service->update($order->id, $this->payload());
    }

    public function test_halaman_edit_terbuka_dan_simpan_lewat_http(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $order = $this->service->create($this->payload());

        $this->actingAs($admin)->get(route('production.orders.edit', $order->id))
            ->assertOk()
            ->assertSee('Simpan Perubahan')
            ->assertSee($order->order_number);

        $this->actingAs($admin)
            ->put(route('production.orders.update', $order->id), $this->payload([
                'materials' => [['product_id' => $this->bahanB, 'qty_required' => 4, 'unit' => 'pcs']],
            ]))
            ->assertRedirect(route('production.orders.show', $order->id));

        $this->assertSame($this->bahanB, (int) $order->materials()->value('product_id'));

        // Setelah dikonfirmasi, halaman edit menolak.
        $order->update(['status' => 'confirmed']);
        $this->actingAs($admin)->get(route('production.orders.edit', $order->id))
            ->assertRedirect(route('production.orders.show', $order->id));
    }
}
