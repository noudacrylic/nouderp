<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Models\CrmStockWatch;
use App\Modules\CRM\Services\StockWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Titipan "kabari saya kalau stoknya ada".
 *
 * Kehilangan penjualan yang paling mudah diselamatkan: pembelinya sudah mau,
 * cuma datang di waktu yang salah. Yang dijaga di sini adalah hal-hal yang
 * kegagalannya TIDAK menimbulkan gejala apa pun — pelanggan cuma diam lalu
 * belanja di tempat lain, dan tak ada seorang pun yang tahu:
 *
 *  1. kabar berangkat SEKALI, tidak dua kali (pelanggan yang diberi tahu dua
 *     kali menganggapnya spam);
 *  2. tandanya lepas setelah dikabari, tapi HANYA kalau kabarnya benar-benar
 *     masuk antrean;
 *  3. yang menunggu 10 pcs tidak dikabari saat yang masuk cuma 1;
 *  4. stok yang bebas karena pesanan orang lain BATAL juga menyalakan kabar —
 *     ledger tidak bergerak sama sekali di peristiwa itu.
 */
class TitipanStokTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function chat(string $nomor = '628111111111'): CrmConversation
    {
        return CrmConversation::create([
            'channel'      => 'whatsapp',
            'contact_key'  => $nomor,
            'display_name' => 'Budi',
            'status'       => CrmConversation::STATUS_AKTIF,
        ]);
    }

    private function sku(string $sku = 'AF-A3', string $nama = 'Akrilik Frame Poster A3'): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => $nama, 'is_active' => true, 'is_sellable' => true,
        ]);
    }

    private function gudang(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['name' => 'Gudang Jual'],
            ['is_active' => true, 'is_sellable' => true]
        );
    }

    private function stok(Product $p, float $qty): void
    {
        ProductStock::updateOrCreate(
            ['product_id' => $p->id, 'warehouse_id' => $this->gudang()->id],
            ['qty_on_hand' => $qty]
        );
    }

    private function titipan(): StockWatchService
    {
        return app(StockWatchService::class);
    }

    /* ------------------------------------------------------------- menandai */

    public function test_menandai_dari_layar_chat(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.titipan-stok.simpan', $chat), ['product_id' => $p->id])
            ->assertOk()
            ->assertJsonPath('ditandai', true);

        $watch = CrmStockWatch::firstOrFail();

        $this->assertSame($chat->id, $watch->conversation_id);
        // Nomornya disalin saat ditandai — percakapan bisa berganti nama.
        $this->assertSame('628111111111', $watch->recipient);
    }

    /** Menandai ulang pasangan yang sama tidak melahirkan titipan kedua. */
    public function test_menandai_dua_kali_tetap_satu_titipan(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('crm.inbox.titipan-stok.simpan', $chat), ['product_id' => $p->id])->assertOk();
        $this->actingAs($admin)->postJson(route('crm.inbox.titipan-stok.simpan', $chat), ['product_id' => $p->id, 'qty' => 5])->assertOk();

        $this->assertSame(1, CrmStockWatch::count());
        $this->assertEquals(5, CrmStockWatch::first()->qty);
    }

    /**
     * Kalau stoknya ternyata sudah ada, kabarnya langsung diantrekan dan
     * tandanya tidak jadi dipasang — barangnya masuk kemarin, pelanggannya
     * belum sempat dikabari.
     */
    public function test_stok_yang_sudah_ada_langsung_dikabari_saat_ditandai(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();
        $this->stok($p, 3);

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.titipan-stok.simpan', $chat), ['product_id' => $p->id])
            ->assertOk()
            ->assertJsonPath('ditandai', false);

        $this->assertSame(1, CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_STOK_TERSEDIA)->count());
        $this->assertSame(0, CrmStockWatch::aktif()->count());
    }

    public function test_titipan_bisa_dibatalkan_tanpa_mengabari(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $watch = $this->titipan()->tandai($chat, $p);

        $this->actingAs($this->admin())
            ->deleteJson(route('crm.titipan-stok.destroy', $watch))
            ->assertOk();

        $this->assertSame(0, CrmStockWatch::aktif()->count());
        $this->assertSame(0, CrmOutboxMessage::count());
        // Barisnya TIDAK dihapus: riwayatnya yang menjawab "kenapa/kenapa tidak".
        $this->assertSame(1, CrmStockWatch::count());
    }

    /**
     * Jumlah yang ditunggu ikut terbaca panel, bukan cuma "ditandai".
     *
     * Kasus yang sebenarnya bukan "stok habis" melainkan "stok tinggal 5, yang
     * dibutuhkan 10". Tanpa angkanya di layar, admin tidak bisa membedakan
     * titipan yang sudah hampir terpenuhi dari yang masih jauh.
     */
    public function test_panel_melaporkan_titipan_beserta_jumlahnya(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();
        $this->stok($p, 5);

        $store = \App\Models\StoreProduct::create([
            'name' => 'Akrilik Frame Poster A3', 'slug' => 'frame-a3', 'status' => 'published',
        ]);
        \App\Models\StoreProductVariant::create([
            'store_product_id' => $store->id, 'product_id' => $p->id, 'variant_label' => 'std',
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('crm.inbox.titipan-stok.simpan', $chat), ['product_id' => $p->id, 'qty' => 10])
            ->assertOk()
            // Stok 5 < 10 → belum dikabari, tandanya terpasang.
            ->assertJsonPath('ditandai', true);

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['chat' => $chat->id, 'q' => 'AF-A3']))
            ->assertOk()
            ->assertJsonPath('grup.0.varian.0.titipan_qty', 10);
    }

    /* ----------------------------------------------------------- mengabari */

    public function test_stok_masuk_mengantrekan_kabar_lalu_melepas_tanda(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $this->titipan()->tandai($chat, $p);
        $this->assertSame(0, CrmOutboxMessage::count());

        $this->stok($p, 4);
        $hasil = $this->titipan()->periksa();

        $this->assertSame(1, $hasil['dikabari']);

        $baris = CrmOutboxMessage::firstOrFail();
        $this->assertSame(CrmOutboxMessage::EVENT_STOK_TERSEDIA, $baris->event);
        $this->assertSame('628111111111', $baris->recipient);
        $this->assertSame($chat->id, $baris->conversation_id);
        /*
         * Empat variabel: nama, produk, TAUTAN, nomor admin. Dua yang terakhir
         * bukan hiasan — kabar ini pemberitahuan, bukan ajakan membalas chat,
         * jadi jalan meneruskannya harus ada di dalam pesannya sendiri.
         * Produk tanpa halaman terbit jatuh ke etalase depan, supaya kalimatnya
         * tidak pernah berakhir dengan tautan kosong.
         */
        $this->assertSame([
            'Budi',
            'Akrilik Frame Poster A3',
            rtrim((string) config('crm.storefront_url'), '/'),
            (string) config('crm.admin_phone'),
        ], $baris->template_body);

        $this->assertSame(0, CrmStockWatch::aktif()->count());
        $this->assertNotNull(CrmStockWatch::first()->notified_at);
    }

    /** SKU yang halamannya terbit membawa tautan produknya sendiri, bukan etalase depan. */
    public function test_tautan_menunjuk_halaman_produk_bila_terbit(): void
    {
        config(['crm.storefront_url' => 'https://noudakrilik.com']);

        $chat = $this->chat();
        $p    = $this->sku();

        $store = \App\Models\StoreProduct::create([
            'name' => 'Akrilik Frame Poster A3', 'slug' => 'frame-a3', 'status' => 'published',
        ]);
        \App\Models\StoreProductVariant::create([
            'store_product_id' => $store->id, 'product_id' => $p->id, 'variant_label' => 'std',
        ]);

        $this->titipan()->tandai($chat, $p);
        $this->stok($p, 2);
        $this->titipan()->periksa();

        $this->assertSame(
            'https://noudakrilik.com/produk/frame-a3',
            CrmOutboxMessage::firstOrFail()->template_body[2]
        );
    }

    /** Pemeriksaan berulang tidak melahirkan pesan kedua. */
    public function test_kabar_hanya_berangkat_sekali(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $this->titipan()->tandai($chat, $p);
        $this->stok($p, 4);

        $this->titipan()->periksa();
        $this->titipan()->periksa();
        $this->titipan()->periksa();

        $this->assertSame(1, CrmOutboxMessage::count());
    }

    /** Yang menunggu 10 tidak terbantu oleh kabar saat yang masuk cuma 1. */
    public function test_jumlah_kurang_dari_yang_ditunggu_belum_dikabari(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $this->titipan()->tandai($chat, $p, 10);

        $this->stok($p, 4);
        $this->assertSame(0, $this->titipan()->periksa()['dikabari']);
        $this->assertSame(1, CrmStockWatch::aktif()->count());

        $this->stok($p, 10);
        $this->assertSame(1, $this->titipan()->periksa()['dikabari']);
    }

    /**
     * Stok yang habis karena DIPESAN belum boleh dikabari — angkanya harus
     * angka yang sama dengan yang dilihat pembeli di web (stok siap), bukan
     * stok fisik.
     */
    public function test_stok_yang_sudah_direservasi_tidak_dihitung(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $this->titipan()->tandai($chat, $p);
        $this->stok($p, 5);

        $reservasi = StockReservation::create([
            'product_id'     => $p->id,
            'warehouse_id'   => $this->gudang()->id,
            'qty'            => 5,
            'status'         => 'active',
            'sales_order_id' => 0,
        ]);

        $this->assertSame(0, $this->titipan()->periksa()['dikabari']);

        /*
         * Pesanan orang lain batal → barangnya bebas lagi, dan OBSERVER-lah
         * yang menyalakan kabarnya (tanpa satu pun pemeriksaan manual di bawah
         * ini). Ledger stok TIDAK bergerak sedikit pun di peristiwa ini; kalau
         * pemantauan hanya digantung di ledger, pelanggan ini tak pernah
         * dikabari.
         */
        $reservasi->delete();

        $this->assertSame(1, CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_STOK_TERSEDIA)->count());
        $this->assertSame(0, CrmStockWatch::aktif()->count());
    }

    /**
     * Jenisnya dimatikan → titipan DIBIARKAN aktif, bukan diantrekan sebagai
     * 'dilewati'. Mengantrekannya membakar kunci dedupe, dan kabarnya tak akan
     * pernah bisa berangkat lagi meski jenisnya dinyalakan kembali.
     */
    public function test_jenis_yang_dimatikan_menyimpan_titipannya(): void
    {
        config(['crm.notifikasi.aktif' => [CrmOutboxMessage::EVENT_STOK_TERSEDIA => false]]);

        $chat = $this->chat();
        $p    = $this->sku();

        $this->titipan()->tandai($chat, $p);
        $this->stok($p, 4);

        $this->assertSame(0, $this->titipan()->periksa()['dikabari']);
        $this->assertSame(0, CrmOutboxMessage::count());
        $this->assertSame(1, CrmStockWatch::aktif()->count());

        config(['crm.notifikasi.aktif' => [CrmOutboxMessage::EVENT_STOK_TERSEDIA => true]]);

        $this->assertSame(1, $this->titipan()->periksa()['dikabari']);
    }

    /** Perintah terjadwal menempuh jalan yang sama dengan pemicu otomatisnya. */
    public function test_perintah_terjadwal_mengabari_juga(): void
    {
        $chat = $this->chat();
        $p    = $this->sku();

        $this->titipan()->tandai($chat, $p);
        $this->stok($p, 2);

        $this->artisan('crm:cek-stok-titipan')->assertSuccessful();

        $this->assertSame(1, CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_STOK_TERSEDIA)->count());
    }
}
