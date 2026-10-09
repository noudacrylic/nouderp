<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use App\Modules\CRM\Services\LabelPesananService;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CRM Tahap 3 — label chat mengikuti tahapan pesanan tertaut (disepakati 8 Okt 2026).
 *
 *  Custom : SO draft → Menunggu Pembayaran → bayar → Desain → OP dikerjakan
 *           → Produksi → OP final → Menunggu Pelunasan / Menunggu Dikirim
 *           → dikirim/diserahkan → Selesai
 *  Ready  : SO draft → Menunggu Pembayaran → bayar → Menunggu Dikirim → Selesai
 *  Shopee : ditautkan → Print → dikirim → Selesai
 */
class LabelPesananTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');

        // Label kerja ini dibuat lewat layar di server, bukan migrasi.
        foreach (['tanya_harga' => 'Tanya Harga', 'cetak' => 'Print', 'desain' => 'Desain', 'produksi' => 'Produksi'] as $kode => $nama) {
            CrmLabel::create(['kode' => $kode, 'nama' => $nama, 'warna' => 'blue', 'urutan' => 10, 'aktif' => true]);
        }

        $this->gudang = Warehouse::create(['name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true])->id;
        $this->admin  = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function produk(bool $custom): int
    {
        return Product::create([
            'sku' => $custom ? 'CS-01' : 'RD-01', 'name' => $custom ? 'Plakat Custom' : 'Frame Ready',
            'sale_type' => $custom ? 'preorder' : 'ready', 'made_to_order' => $custom,
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;
    }

    private function chat(string $nomor = '628998844666'): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor);
        $p->forceFill(['window_expires_at' => now()->addHours(5), 'last_message_at' => now(),
            'owner_user_id' => $this->admin->id, 'queue_state' => 'tanya_harga'])->save();

        return $p->fresh();
    }

    /** SO dibuat dari chat — jalur yang sama dengan tombol "Buat SO Draft". */
    private function soDariChat(CrmConversation $chat, int $produk): SalesOrder
    {
        $this->actingAs($this->admin)
            ->postJson(route('crm.inbox.buat-so', $chat), [
                'warehouse_id' => $this->gudang, 'customer_name' => 'Pak Budi',
                'shipping_address' => 'Jl. Melati 3',
                'items' => [['product_id' => $produk, 'qty' => 1, 'unit_price' => 100000,
                             'discount_type' => 'nominal', 'discount_value' => 0]],
            ])->assertOk();

        return SalesOrder::latest('id')->first();
    }

    private function bayar(SalesOrder $so, float $jumlah): void
    {
        SalesAdvance::create([
            'advance_number' => 'DP-' . uniqid(), 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id,
            'bank_account_id' => 1, 'advance_date' => now()->toDateString(), 'amount' => $jumlah, 'status' => 'posted',
        ]);
    }

    private function op(SalesOrder $so, string $status): ProductionOrder
    {
        $op = new ProductionOrder();
        $op->forceFill([
            'order_number' => 'OP-' . uniqid(), 'type' => 'custom', 'warehouse_id' => $this->gudang,
            'production_date' => now()->toDateString(), 'sales_order_id' => $so->id, 'status' => $status,
        ])->save();

        return $op;
    }

    private function label(CrmConversation $chat): string
    {
        return (string) $chat->fresh()->queue_state;
    }

    /* ------------------------------------------------------------- ready */

    public function test_alur_ready_stock(): void
    {
        $chat = $this->chat();
        $so   = $this->soDariChat($chat, $this->produk(false));

        $this->assertSame(LabelPesananService::MENUNGGU_PEMBAYARAN, $this->label($chat), 'SO draft dari chat langsung tertaut');

        $so->forceFill(['status' => 'confirmed'])->save();
        $this->assertSame(LabelPesananService::MENUNGGU_PEMBAYARAN, $this->label($chat));

        $this->bayar($so, 50000);
        $this->assertSame(LabelPesananService::MENUNGGU_DIKIRIM, $this->label($chat));

        $sj = new SalesDelivery();
        $sj->forceFill(['delivery_number' => 'SJ-1', 'warehouse_id' => $this->gudang, 'delivery_date' => now()->toDateString(),
            'sales_order_id' => $so->id, 'status' => 'posted', 'tracking_number' => 'JNE123'])->save();
        $this->assertSame(LabelPesananService::SELESAI, $this->label($chat), 'Diserahkan ke kurir = Selesai');
    }

    /* ------------------------------------------------------------ custom */

    public function test_alur_custom_sampai_menunggu_pelunasan(): void
    {
        $chat = $this->chat();
        $so   = $this->soDariChat($chat, $this->produk(true));
        $so->forceFill(['status' => 'confirmed'])->save();

        $this->bayar($so, 50000);   // DP 50%
        // OP preorder bisa lahir otomatis saat DP — selama belum dikerjakan tetap Desain.
        $this->assertSame(LabelPesananService::DESAIN, $this->label($chat));

        $op = $so->productionOrders()->first() ?? $this->op($so, 'confirmed');
        $this->assertSame(LabelPesananService::DESAIN, $this->label($chat));

        $op->forceFill(['status' => 'in_progress'])->save();
        $this->assertSame(LabelPesananService::PRODUKSI, $this->label($chat));

        $op->forceFill(['status' => 'finalized'])->save();
        $this->assertSame(LabelPesananService::MENUNGGU_PELUNASAN, $this->label($chat));

        $this->bayar($so, 50000);   // pelunasan
        $this->assertSame(LabelPesananService::MENUNGGU_DIKIRIM, $this->label($chat));
    }

    public function test_preorder_umum_ikut_alur_custom(): void
    {
        $preorder = Product::create([
            'sku' => 'PO-01', 'name' => 'Rak Preorder', 'sale_type' => 'preorder', 'made_to_order' => false,
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        $chat = $this->chat();
        $so   = $this->soDariChat($chat, $preorder);
        $so->forceFill(['status' => 'confirmed'])->save();

        $this->bayar($so, 50000);
        $this->assertSame(LabelPesananService::DESAIN, $this->label($chat), 'Bukan Menunggu Dikirim — barang belum diproduksi');

        $op = $so->productionOrders()->first() ?? $this->op($so, 'confirmed');
        $op->forceFill(['status' => 'in_progress'])->save();
        $this->assertSame(LabelPesananService::PRODUKSI, $this->label($chat));
    }

    /* ------------------------------------------------------- marketplace */

    public function test_pesanan_shopee_ditautkan_jadi_print_lalu_selesai_saat_dikirim(): void
    {
        $chat = $this->chat();
        $so   = $this->soDariChat($this->chat('628111000999'), $this->produk(false));   // SO milik chat lain
        $so->forceFill(['status' => 'confirmed', 'customer_po_number' => '2410SHOPEE01'])->save();
        $link = JubelioOrderLink::create(['jubelio_salesorder_id' => 777, 'jubelio_salesorder_no' => '2410SHOPEE01', 'sales_order_id' => $so->id]);

        // Dicari dengan nomor Shopee yang diminta dari pelanggan, lalu ditautkan.
        $this->actingAs($this->admin)->getJson(route('crm.inbox.pesanan.cari', ['q' => 'SHOPEE01']))
            ->assertOk()->assertJsonPath('hasil.0.id', $so->id);

        $this->actingAs($this->admin)->postJson(route('crm.inbox.pesanan.tautkan', [$chat, $so]))
            ->assertOk()->assertJsonPath('label', 'Print');

        $link->forceFill(['shipped_at' => now()])->save();
        $this->assertSame(LabelPesananService::SELESAI, $this->label($chat));
    }

    /**
     * Order custom via marketplace diperlakukan seperti custom biasa, tanpa
     * tahap pembayaran (sudah lunas di marketplace). Dikenali dari ADANYA OP,
     * karena SKU-nya sering produk biasa.
     */
    public function test_pesanan_marketplace_ber_op_ikut_alur_custom_tanpa_tahap_bayar(): void
    {
        $chat = $this->chat();
        $so   = $this->soDariChat($this->chat('628111000777'), $this->produk(false));   // SKU biasa
        $so->forceFill(['status' => 'confirmed'])->save();
        $link = JubelioOrderLink::create(['jubelio_salesorder_id' => 778, 'jubelio_salesorder_no' => 'SP-CUSTOM', 'sales_order_id' => $so->id]);

        app(LabelPesananService::class)->tautkan($chat, $so);
        $this->assertSame(LabelPesananService::PRINT, $this->label($chat), 'Belum ada OP = Print');

        $op = $this->op($so, 'confirmed');
        $this->assertSame(LabelPesananService::DESAIN, $this->label($chat), 'OP ada, belum dikerjakan = Desain');

        $op->forceFill(['status' => 'in_progress'])->save();
        $this->assertSame(LabelPesananService::PRODUKSI, $this->label($chat));

        $op->forceFill(['status' => 'finalized'])->save();
        $this->assertSame(LabelPesananService::MENUNGGU_DIKIRIM, $this->label($chat), 'Tak pernah Menunggu Pelunasan — lunas di marketplace');

        $link->forceFill(['shipped_at' => now()])->save();
        $this->assertSame(LabelPesananService::SELESAI, $this->label($chat));
    }

    public function test_pesanan_marketplace_produk_custom_tanpa_op_langsung_desain(): void
    {
        $chat = $this->chat();
        $so   = $this->soDariChat($this->chat('628111000666'), $this->produk(true));
        $so->forceFill(['status' => 'confirmed'])->save();
        JubelioOrderLink::create(['jubelio_salesorder_id' => 779, 'jubelio_salesorder_no' => 'SP-CS', 'sales_order_id' => $so->id]);

        // OP preorder bisa lahir otomatis; dibuang supaya kasus "tanpa OP" teruji.
        $so->productionOrders()->delete();

        app(LabelPesananService::class)->tautkan($chat, $so);

        $this->assertSame(LabelPesananService::DESAIN, $this->label($chat));
    }

    /* ------------------------------------------------------------- aturan */

    public function test_label_mengikuti_pesanan_terbaru_saja(): void
    {
        $chat = $this->chat();
        $lama = $this->soDariChat($chat, $this->produk(false));
        $baru = $this->soDariChat($chat, Product::where('sku', 'RD-01')->value('id'));

        // Pesanan LAMA dibayar: label tidak boleh bergeser — yang terbaru masih draft.
        $lama->forceFill(['status' => 'confirmed'])->save();
        $this->bayar($lama, 100000);

        $this->assertSame(LabelPesananService::MENUNGGU_PEMBAYARAN, $this->label($chat));
    }

    public function test_distributor_tidak_digeser_pesanan(): void
    {
        $chat = $this->chat();
        $chat->forceFill(['is_distributor' => true, 'queue_state' => CrmConversation::LABEL_DISTRIBUTOR])->save();

        $this->soDariChat($chat, $this->produk(false));

        $this->assertSame(CrmConversation::LABEL_DISTRIBUTOR, $this->label($chat));
    }

    public function test_pesanan_yang_dilepas_tidak_lagi_menggerakkan_label(): void
    {
        $chat = $this->chat();
        $so   = $this->soDariChat($chat, $this->produk(false));

        $this->actingAs($this->admin)->postJson(route('crm.inbox.pesanan.lepas', [$chat, $so]))->assertOk();
        $chat->fresh()->forceFill(['queue_state' => 'tanya_harga'])->save();

        $so->forceFill(['status' => 'confirmed'])->save();
        $this->bayar($so, 100000);

        $this->assertSame('tanya_harga', $this->label($chat));
    }

    /** SO diketik di modul Sales (orang pesan lewat WA) — tidak lewat tombol di chat. */
    private function soDiSales(SalesOrder $contoh): SalesOrder
    {
        $so = $contoh->replicate(['public_token']);
        $so->forceFill(['order_number' => 'SO-' . uniqid(), 'status' => 'draft'])->save();

        foreach ($contoh->items as $item) {
            $so->items()->save($item->replicate());
        }

        return $so;
    }

    public function test_so_dibuat_di_sales_tertaut_otomatis_ke_satu_satunya_chat_pelanggan(): void
    {
        $chat = $this->chat();
        $lama = $this->soDariChat($chat, $this->produk(true));
        app(LabelPesananService::class)->lepas($chat, $lama);
        $chat->forceFill(['queue_state' => 'tanya_harga'])->save();

        $so = $this->soDiSales($lama);
        $this->assertSame(LabelPesananService::MENUNGGU_PEMBAYARAN, $this->label($chat), 'Tertaut saat lahir');

        $so->forceFill(['status' => 'confirmed'])->save();
        $this->bayar($so, 50000);
        $op = $so->productionOrders()->first() ?? $this->op($so, 'confirmed');
        $op->forceFill(['status' => 'in_progress'])->save();

        $this->assertSame(LabelPesananService::PRODUKSI, $this->label($chat));
    }

    public function test_pelanggan_dengan_dua_chat_tidak_ditautkan_otomatis(): void
    {
        $chat = $this->chat();
        $lama = $this->soDariChat($chat, $this->produk(false));
        $this->chat('628111000555')->forceFill(['customer_id' => $lama->customer_id])->save();

        $so = $this->soDiSales($lama);

        $this->assertDatabaseMissing('crm_pesanan_chat', ['sales_order_id' => $so->id]);
    }

    public function test_tautkan_pesanan_lama_hanya_yang_masih_berjalan(): void
    {
        $chat = $this->chat();
        $berjalan = $this->soDariChat($chat, $this->produk(true));
        $berjalan->forceFill(['status' => 'confirmed'])->save();
        $this->bayar($berjalan, 50000);

        $selesai = $this->soDariChat($chat, Product::where('sku', 'CS-01')->value('id'));
        $selesai->forceFill(['status' => 'confirmed'])->save();
        $this->bayar($selesai, 100000);
        $sj = new SalesDelivery();
        $sj->forceFill(['delivery_number' => 'SJ-9', 'warehouse_id' => $this->gudang, 'delivery_date' => now()->toDateString(),
            'sales_order_id' => $selesai->id, 'status' => 'posted', 'tracking_number' => 'JNE9'])->save();

        // Keadaan sebelum 9 Okt: tak satu pun tertaut.
        \DB::table('crm_pesanan_chat')->delete();
        $chat->forceFill(['queue_state' => 'tanya_harga'])->save();

        $this->artisan('crm:tautkan-pesanan-lama', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('crm_pesanan_chat', 0);

        $this->artisan('crm:tautkan-pesanan-lama')->assertSuccessful();

        $this->assertDatabaseHas('crm_pesanan_chat', ['sales_order_id' => $berjalan->id, 'conversation_id' => $chat->id]);
        $this->assertDatabaseMissing('crm_pesanan_chat', ['sales_order_id' => $selesai->id]);
        $this->assertSame(LabelPesananService::DESAIN, $this->label($chat));
    }

    public function test_tautkan_wajib_oper_dulu(): void
    {
        $chat = $this->chat();
        $chat->forceFill(['owner_user_id' => null])->save();
        $so = $this->soDariChat($this->chat('628111000888'), $this->produk(false));

        $this->actingAs($this->admin)->postJson(route('crm.inbox.pesanan.tautkan', [$chat, $so]))->assertStatus(403);
    }
}
