<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pesanan custom (CS*) yang sudah dibayar tapi belum ber-OP → kartu Meja Owner (18 Nov 2026,
 * kasus Gudang Pigura: link CS3 dikirim lewat WA, dibayar di Shopee tanpa kabar).
 */
class PesananCustomDibayarTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'pesanan-custom-dibayar';

    private User $owner;
    private int $gudang;
    private int $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner     = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->gudang    = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->pelanggan = Customer::create(['code' => 'C1', 'name' => 'Shopee Pembeli', 'is_active' => true])->id;
        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
    }

    private function produk(string $sku, bool $custom = true, string $saleType = 'preorder'): Product
    {
        return Product::create(['sku' => $sku, 'name' => "Produk $sku", 'sale_type' => $saleType, 'made_to_order' => $custom,
            'base_unit' => 'pcs', 'base_price' => 10000, 'is_active' => true, 'is_sellable' => true]);
    }

    private function so(Product $p, int $qty, array $atribut = []): SalesOrder
    {
        static $n = 0;
        $n++;
        $so = SalesOrder::create($atribut + ['order_number' => "SO-PC-$n", 'customer_id' => $this->pelanggan, 'warehouse_id' => $this->gudang,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 800000]);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => 'Akrilik Custom 3',
            'qty' => $qty, 'unit_price' => 400000, 'net_unit_price' => 400000, 'line_subtotal' => 400000 * $qty, 'line_discount' => 0, 'line_total' => 400000 * $qty]);
        return $so;
    }

    private function op(SalesOrder $so, Product $p, int $qty, string $status = 'confirmed'): void
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-PC-$n", 'type' => 'custom', 'sales_order_id' => $so->id, 'warehouse_id' => $this->gudang,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => $status, 'score_type' => 'auto', 'created_via' => 'manual']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $p->id, 'qty_planned' => $qty, 'output_type' => 'main', 'percentage' => 100]);
    }

    public function test_pesanan_shopee_dibayar_muncul_di_meja_owner_dengan_chat_wa_asal_lalu_selesai_saat_op_dibuat(): void
    {
        $cs3 = $this->produk('CS3');
        $wa  = CrmConversation::findOrCreateFor('6281234', CrmConversation::KANAL_CERMIN);
        $wa->forceFill(['display_name' => 'Gudang Pigura Samarinda'])->save();
        CrmMessage::create(['conversation_id' => $wa->id, 'direction' => 'keluar', 'source' => 'erp', 'status' => 'terkirim',
            'content' => "Berikut link Produk Custom 3:\nhttps://shopee.co.id/product/101215182/8850524768/", 'sent_at' => now()->subHour()]);

        $so = $this->so($cs3, 2, ['customer_po_number' => 'SP-261116EWXPM4J4']);
        DB::table('shopee_orders')->insert(['env' => 'live', 'shop_id' => 101215182, 'order_sn' => '261116EWXPM4J4', 'status' => 'READY_TO_SHIP',
            'sales_order_id' => $so->id, 'raw' => json_encode(['item_list' => [['item_id' => 8850524768, 'item_name' => 'Akrilik Custom 3']]]),
            'created_at' => now(), 'updated_at' => now()]);
        $meja = app(MejaKerjaService::class);

        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', self::SKILL)->count(), 'Belum dibayar → belum ada kartu.');

        $so->update(['paid_amount' => 800000]);
        $meja->sinkron();
        $t = Task::where('skill', self::SKILL)->firstOrFail();
        $this->assertSame('owner', $t->peran);
        $this->assertSame($this->owner->id, $t->assignee_user_id);
        $this->assertStringContainsString('2× CS3 (Akrilik Custom 3)', (string) $t->description);
        $this->assertStringContainsString('Gudang Pigura Samarinda', (string) $t->description);
        $aksi = collect($meja->skill(self::SKILL)->aksi($t));
        $this->assertStringContainsString('type=custom', $aksi->firstWhere('label', 'Buat OP')['url']);
        $this->assertSame(route('crm.inbox.show', $wa->id), $aksi->firstWhere('label', 'Chat WA')['url']);

        $meja->sinkron();
        $this->assertSame(1, Task::where('skill', self::SKILL)->count(), 'Tidak dobel.');

        $this->op($so, $cs3, 2, 'cancelled');
        $meja->sinkron();
        $this->assertSame('open', $t->fresh()->status, 'OP batal tidak dihitung.');

        $this->op($so, $cs3, 2);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_produk_biasa_dan_pesanan_untuk_admin_desain_tidak_masuk_meja_owner(): void
    {
        $this->so($this->produk('RP-3x5', false, 'ready'), 5, ['paid_amount' => 100000]);
        $this->so($this->produk('PO-UMUM', false), 5, ['paid_amount' => 100000]);   // pre-order umum, stok bersama
        $this->so($this->produk('CS1'), 10, ['paid_amount' => 100000, 'desain_gambar' => true]);
        app(MejaKerjaService::class)->sinkron();

        $this->assertSame(0, Task::where('skill', self::SKILL)->count());
    }

    public function test_so_void_atau_diserahkan_ke_admin_desain_kartu_batal(): void
    {
        $cs = $this->produk('CS2');
        $a = $this->so($cs, 1, ['paid_amount' => 400000]);
        $b = $this->so($cs, 1, ['is_tempo' => true]);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $this->assertSame(2, Task::where('skill', self::SKILL)->where('status', 'open')->count(), 'Tempo juga dihitung boleh jalan.');

        $a->update(['status' => 'void']);
        $b->update(['desain_gambar' => true]);
        $meja->sinkron();
        $this->assertSame(['cancelled', 'cancelled'], Task::where('skill', self::SKILL)->orderBy('id')->pluck('status')->all());
    }
}
