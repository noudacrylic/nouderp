<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Target kartu "Desain pesanan" = batas kirim kartu Pemrosesan Pesanan. */
class DesainBatasKirimTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_kartu_desain_ikut_batas_kirim_marketplace_dan_bergeser(): void
    {
        $desainer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $desainer->id, 'urutan' => 1]);
        MejaKerjaSkill::updateOrCreate(['skill' => 'desain'], ['peran' => 'desain', 'bobot' => 8, 'sla_menit' => 120]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => 'admin.desain'], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);

        $gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $cust   = Customer::create(['code' => 'C1', 'name' => 'Pembeli', 'is_active' => true])->id;
        $ks     = Product::create(['sku' => 'KS-K1-M3', 'name' => 'Kotak Saran', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 10000, 'is_active' => true, 'is_sellable' => true]);

        $so = SalesOrder::create(['order_number' => 'SO-BK-1', 'customer_id' => $cust, 'warehouse_id' => $gudang,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 10000, 'grand_total' => 10000,
            'customer_po_number' => 'SP-ABC123']);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $ks->id, 'description' => 'Kotak Saran',
            'qty' => 1, 'unit_price' => 10000, 'net_unit_price' => 10000, 'line_subtotal' => 10000, 'line_discount' => 0, 'line_total' => 10000]);

        $due = Carbon::now()->addDay()->setTime(23, 59);
        $link = JubelioOrderLink::create(['jubelio_salesorder_id' => 1, 'sales_order_id' => $so->id]);
        DB::table('jubelio_order_links')->where('id', $link->id)->update(['mp_due_date' => $due]);

        $meja = app(MejaKerjaService::class);
        $meja->sinkron();

        $t = Task::where('skill', 'desain')->firstOrFail();
        $this->assertSame($due->format('Y-m-d H:i'), $t->tenggat_at->format('Y-m-d H:i'), 'Kartu baru langsung ikut batas kirim.');
        $this->assertTrue((bool) ($t->meta['batas_kirim'] ?? false));

        // Batas kirim marketplace bergeser → kartu ikut dibetulkan.
        $baru = Carbon::now()->addDays(2)->setTime(12, 0);
        DB::table('jubelio_order_links')->where('id', $link->id)->update(['mp_due_date' => $baru]);
        $meja->sinkron();
        $this->assertSame($baru->format('Y-m-d H:i'), $t->fresh()->tenggat_at->format('Y-m-d H:i'));
    }
}
