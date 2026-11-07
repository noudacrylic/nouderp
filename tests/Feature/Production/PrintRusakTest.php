<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\FifoService;
use App\Core\Inventory\Product;
use App\Core\Journal\Journal;
use App\Enums\AccountCodeEnum;
use App\Models\User;
use App\Modules\Production\PrintRusak\PrintRusak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Print rusak: stok bahan keluar FIFO, Dr 5007 / Cr 1130, batal mengembalikan semuanya. */
class PrintRusakTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private int $gudang;
    private Product $vynil;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([[AccountCodeEnum::INVENTORY, 'Persediaan', 'asset', 'debit'], [AccountCodeEnum::PRODUCTION_OVERHEAD, 'Beban Overhead Produksi', 'expense', 'debit']] as [$c, $n, $t, $nb]) {
            DB::table('accounts')->insert(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'user', 'is_active' => true]);
        DB::table('meja_kerja_peran')->insert(['peran' => 'desain', 'user_id' => $this->admin->id, 'urutan' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->gudang = DB::table('warehouses')->insertGetId(['name' => 'Utama', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->vynil = Product::create(['sku' => 'PRD00006', 'name' => 'Stiker Vynil A3+ Transparan', 'sale_type' => 'ready', 'base_unit' => 'Lembar', 'base_price' => 0, 'is_active' => true]);
        $fifo = app(FifoService::class);
        $fifo->stockIn($this->vynil->id, $this->gudang, 'purchase', 'PB-1', 10, 3000, 1);
        $fifo->stockIn($this->vynil->id, $this->gudang, 'purchase', 'PB-2', 10, 4000, 2);
        DB::table('print_rusak_bahan')->insert(['product_id' => $this->vynil->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stok(): float
    {
        return (float) DB::table('product_stocks')->where('product_id', $this->vynil->id)->where('warehouse_id', $this->gudang)->value('qty_on_hand');
    }

    public function test_lapor_mengurangi_stok_dan_jurnal_5007_lalu_batal_mengembalikan(): void
    {
        $this->actingAs($this->admin)->get(route('tasks.meja.print-rusak.index'))->assertOk()->assertSee('Stiker Vynil A3+ Transparan');

        $this->actingAs($this->admin)->post(route('tasks.meja.print-rusak.store'), [
            'product_id' => $this->vynil->id, 'warehouse_id' => $this->gudang, 'qty' => '12', 'sebab' => 'paperjam',
        ])->assertSessionHas('success');

        $pr = PrintRusak::sole();
        $this->assertSame(8.0, $this->stok());
        $this->assertEqualsWithDelta(10 * 3000 + 2 * 4000, $pr->nilai, 0.01, 'FIFO: 10 lembar Rp3.000 + 2 lembar Rp4.000.');

        $j = Journal::where('reference_type', 'print_rusak')->where('reference_id', $pr->id)->sole();
        $baris = $j->lines()->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->pluck('journal_lines.debit', 'accounts.code');
        $this->assertEqualsWithDelta(38000, (float) $baris[AccountCodeEnum::PRODUCTION_OVERHEAD], 0.01);
        $this->assertEqualsWithDelta(0, (float) $baris[AccountCodeEnum::INVENTORY], 0.01);

        $this->actingAs($this->admin)->get(route('tasks.meja.print-rusak.index'))->assertSee('Paperjam')->assertSee('Rp38.000');

        // Pelapor boleh batal di hari yang sama.
        $this->actingAs($this->admin)->post(route('tasks.meja.print-rusak.void', $pr->id))->assertSessionHas('success');
        $this->assertSame(20.0, $this->stok());
        $this->assertNotNull($pr->fresh()->voided_at);
        $balik = Journal::where('reference_type', 'print_rusak_void')->where('reference_id', $pr->id)->sole();
        $this->assertEqualsWithDelta(38000, (float) $balik->lines()->sum('credit'), 0.01);

        $this->actingAs($this->admin)->post(route('tasks.meja.print-rusak.void', $pr->id))->assertForbidden();
    }

    public function test_stok_kurang_dan_bahan_tak_terdaftar_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('tasks.meja.print-rusak.store'), [
            'product_id' => $this->vynil->id, 'warehouse_id' => $this->gudang, 'qty' => '25', 'sebab' => 'paperjam',
        ])->assertSessionHasErrors('qty');

        $lain = Product::create(['sku' => 'X-1', 'name' => 'Akrilik 3mm', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 0, 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('tasks.meja.print-rusak.store'), [
            'product_id' => $lain->id, 'warehouse_id' => $this->gudang, 'qty' => '1', 'sebab' => 'paperjam',
        ])->assertSessionHasErrors('qty');

        $this->assertSame(0, PrintRusak::count());
        $this->assertSame(20.0, $this->stok());
    }

    public function test_hanya_super_admin_mengatur_bahan(): void
    {
        $chromo = Product::create(['sku' => 'PRD00007', 'name' => 'Stiker Chromo A3+', 'sale_type' => 'ready', 'base_unit' => 'Lembar', 'base_price' => 0, 'is_active' => true]);

        $this->actingAs($this->owner)->get(route('tasks.meja.print-rusak.index'))->assertOk()->assertSee('+ Tambah bahan')->assertSee('form-tambah-bahan', false);
        $this->actingAs($this->admin)->get(route('tasks.meja.print-rusak.index'))->assertOk()->assertDontSee('+ Tambah bahan');
        $this->actingAs($this->admin)->post(route('tasks.meja.print-rusak.bahan.tambah'), ['product_id' => $chromo->id])->assertForbidden();
        $this->actingAs($this->owner)->post(route('tasks.meja.print-rusak.bahan.tambah'), ['product_id' => $chromo->id])->assertSessionHas('success');
        $this->assertTrue(DB::table('print_rusak_bahan')->where('product_id', $chromo->id)->exists());

        $this->actingAs($this->owner)->delete(route('tasks.meja.print-rusak.bahan.hapus', $chromo->id))->assertSessionHas('success');
        $this->assertFalse(DB::table('print_rusak_bahan')->where('product_id', $chromo->id)->exists());
    }
}
