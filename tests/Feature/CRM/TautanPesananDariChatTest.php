<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Services\LabelPesananService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tautan pesanan yang lahir dari sisi chat (CrmTautanPesananObserver):
 * pembeli web yang checkout dulu baru chat, dan pembeli yang menyebut nomor
 * pesanannya dari nomor WA yang berbeda dengan nomor di checkout.
 */
class TautanPesananDariChatTest extends TestCase
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

        foreach (['tanya_harga' => 'Tanya Harga', 'cetak' => 'Print', 'desain' => 'Desain', 'produksi' => 'Produksi'] as $kode => $nama) {
            CrmLabel::create(['kode' => $kode, 'nama' => $nama, 'warna' => 'blue', 'urutan' => 10, 'aktif' => true]);
        }

        $this->gudang = Warehouse::create(['name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true])->id;
        $this->admin  = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function chat(string $nomor): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor);
        $p->forceFill(['window_expires_at' => now()->addHours(5), 'last_message_at' => now(),
            'owner_user_id' => $this->admin->id, 'queue_state' => 'tanya_harga'])->save();

        return $p->fresh();
    }

    /**
     * SO milik pelanggan, BELUM tertaut ke chat mana pun, dan chat asalnya
     * dikembalikan jadi Lead — keadaan pesanan web yang checkout sebelum chat.
     */
    private function soTanpaTautan(string $nomorPesanan = '2610-1181750'): array
    {
        $produk = Product::create([
            'sku' => 'RD-01', 'name' => 'Frame Ready', 'sale_type' => 'ready', 'made_to_order' => false,
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        $asal = $this->chat('628119990001');
        $this->actingAs($this->admin)
            ->postJson(route('crm.inbox.buat-so', $asal), [
                'warehouse_id' => $this->gudang, 'customer_name' => 'Damaris', 'shipping_address' => 'Lobi Fisip',
                'items' => [['product_id' => $produk, 'qty' => 1, 'unit_price' => 100000,
                             'discount_type' => 'nominal', 'discount_value' => 0]],
            ])->assertOk();

        $so = SalesOrder::latest('id')->first();
        $so->forceFill(['order_number' => $nomorPesanan])->saveQuietly();

        DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->delete();
        // Chat asal dibuang supaya pelanggan ini tidak punya chat sama sekali.
        DB::table('crm_conversations')->where('id', $asal->id)->delete();

        return [$so->fresh(), (int) $so->customer_id];
    }

    private function pesanMasuk(CrmConversation $chat, string $isi): void
    {
        CrmMessage::create([
            'conversation_id' => $chat->id, 'direction' => CrmMessage::MASUK,
            'message_type' => 'text', 'content' => $isi, 'source' => 'test',
        ]);
    }

    private function tertautKe(SalesOrder $so): ?int
    {
        $id = DB::table('crm_pesanan_chat')->where('sales_order_id', $so->id)->value('conversation_id');

        return $id ? (int) $id : null;
    }

    /* ---------------------------------------- B: checkout dulu, baru chat */

    public function test_chat_yang_baru_dikenali_menautkan_pesanan_terbuka_pelanggannya(): void
    {
        [$so, $pelanggan] = $this->soTanpaTautan();
        $chat = $this->chat('628561533363');

        $chat->forceFill(['customer_id' => $pelanggan])->save();   // = cocokkanPelanggan saat pesan masuk

        $this->assertSame($chat->id, $this->tertautKe($so));
        $this->assertSame(LabelPesananService::MENUNGGU_PEMBAYARAN, $chat->fresh()->queue_state);
    }

    public function test_pesanan_lebih_dari_30_hari_tidak_ikut_ditautkan(): void
    {
        [$so, $pelanggan] = $this->soTanpaTautan();
        $so->forceFill(['created_at' => now()->subDays(45)])->saveQuietly();

        $this->chat('628561533363')->forceFill(['customer_id' => $pelanggan])->save();

        $this->assertNull($this->tertautKe($so));
    }

    /* ------------------------------- A: nomor pesanan disebut di pesan masuk */

    public function test_nomor_pesanan_di_pesan_masuk_menautkan_so_dan_mengenali_chat(): void
    {
        [$so, $pelanggan] = $this->soTanpaTautan('2610-1181750');
        $chat = $this->chat('628561533363');   // nomor WA beda dengan nomor checkout

        $this->pesanMasuk($chat, 'kak pesanan saya 2610-1181750 sudah dikirim?');

        $this->assertSame($chat->id, $this->tertautKe($so));
        $this->assertSame($pelanggan, (int) $chat->fresh()->customer_id, 'Lead dikenali sebagai pembeli');
    }

    public function test_nomor_pesanan_tanpa_tanda_hubung_rapat_tidak_dianggap(): void
    {
        [$so] = $this->soTanpaTautan('2610-1181750');
        $chat = $this->chat('628561533363');

        // 11 digit rapat lebih mirip nomor telepon/rekening — tidak ditebak.
        $this->pesanMasuk($chat, 'transfer ke 26101181750');

        $this->assertNull($this->tertautKe($so));
    }

    public function test_so_yang_sudah_tertaut_tidak_dirampas_pesan_orang_lain(): void
    {
        [$so] = $this->soTanpaTautan('2610-1181750');
        $pemilik = $this->chat('628561533363');
        app(LabelPesananService::class)->tautkan($pemilik, $so);

        $lain = $this->chat('628777000111');
        $this->pesanMasuk($lain, '2610-1181750');

        $this->assertSame($pemilik->id, $this->tertautKe($so));
        $this->assertNull($lain->fresh()->customer_id);
    }

    public function test_pesan_keluar_menyebut_nomor_pesanan_tidak_menautkan(): void
    {
        [$so] = $this->soTanpaTautan('2610-1181750');
        $chat = $this->chat('628561533363');

        CrmMessage::create([
            'conversation_id' => $chat->id, 'direction' => CrmMessage::KELUAR,
            'message_type' => 'text', 'content' => 'Pesanan 2610-1181750 sudah dikirim', 'source' => 'test',
        ]);

        $this->assertNull($this->tertautKe($so));
    }
}
