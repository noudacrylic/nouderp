<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Panel cek ongkir di rail CRM.
 *
 * Yang diuji di sini bukan tarifnya (itu urusan provider), melainkan tiga hal
 * yang membuat panelnya hidup atau mati diam-diam: endpoint tarif harus mau
 * menjawab JSON, pencarian produk harus membawa berat, dan menu yang
 * disembunyikan tidak boleh ikut mencabut izin route-nya.
 */
class OngkirRailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function gudang(): Warehouse
    {
        return Warehouse::create(['name' => 'Gudang Utama', 'is_active' => 1]);
    }

    private function percakapan(): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');

        $p->forceFill([
            'window_expires_at' => now()->addHours(5),
            'last_message_at'   => now(),
            'queue_state'       => CrmConversation::QUEUE_KITA,
        ])->save();

        return $p;
    }

    public function test_rail_menyediakan_tab_ongkir_di_layar_chat(): void
    {
        $this->gudang();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $this->percakapan()))
            ->assertOk()
            ->assertSee('Ongkir')
            ->assertSee('Gudang Asal')
            ->assertSee('beratnya dijumlah otomatis');
    }

    /**
     * Endpoint tarif WAJIB mau menjawab JSON.
     *
     * Panel rail memanggil endpoint yang sama dengan halaman lama supaya
     * validasi tujuan dan penjaga gudang tanpa alamat tidak punya dua versi.
     * Kalau ia balas HTML, panelnya gagal tanpa pesan apa pun — persis jenis
     * kerusakan yang baru ketahuan saat pelanggan sedang menunggu jawaban.
     */
    public function test_endpoint_ongkir_menjawab_json_bukan_halaman(): void
    {
        $gudang = $this->gudang();

        $this->actingAs($this->admin())
            ->postJson(route('sales.cek-ongkir.check'), [
                'warehouse_id' => $gudang->id,
                'weight_gram'  => 1500,
                // Tujuan sengaja dikosongkan: yang diuji bentuk jawabannya.
            ])
            ->assertOk()
            ->assertJsonStructure(['rates', 'errors'])
            ->assertJsonPath('errors.0', fn ($p) => str_contains((string) $p, 'alamat tujuan'));
    }

    /**
     * Pencarian produk membawa berat & dimensi.
     *
     * Inilah yang membuat panelnya berguna: admin memilih produk, mesin yang
     * menjumlahkan gramnya. Tanpa medan ini panel diam-diam menghitung ongkir
     * dari berat nol — hasilnya terlihat sah di layar dan baru ketahuan salah
     * setelah terlanjur dijanjikan.
     */
    public function test_pencarian_produk_membawa_berat_dan_dimensi(): void
    {
        Product::create([
            'sku' => 'AKR-001', 'name' => 'Meja Akrilik', 'is_active' => 1, 'is_sellable' => 1,
            'sale_type' => 'ready', 'weight_gram' => 2500,
            'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 12,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/erp/api/products/search?sellable_only=1&q=Akrilik')
            ->assertOk()
            ->assertJsonPath('0.weight_gram', 2500)
            ->assertJsonPath('0.length_cm', 40)
            ->assertJsonPath('0.height_cm', 12);
    }

    /**
     * Menu yang disembunyikan tidak boleh ikut mencabut izin route-nya.
     *
     * EnsureMenuAccess menolak SETIAP route bernama yang tidak punya entri
     * menu. Menghapus "Cek Ongkir" dari daftar — bukan menyembunyikannya —
     * membuat panel CRM, form gudang, popup alamat pelanggan, dan cek ongkir
     * di SO/Faktur balas 403 untuk semua user non-super-admin sekaligus.
     */
    public function test_cek_ongkir_hilang_dari_menu_tapi_izinnya_tetap_ada(): void
    {
        // Diambil lewat array, bukan notasi titik: kunci menunya SENDIRI
        // mengandung titik ('sales.cek-ongkir'), jadi config() akan menyelaminya.
        $anak = config('menu_permissions.sales.children')['sales.cek-ongkir'] ?? null;

        $this->assertNotNull($anak, 'Entri menu wajib tetap ada demi EnsureMenuAccess.');
        $this->assertTrue($anak['hidden'] ?? false, 'Entrinya harus disembunyikan dari bilah menu.');

        $this->assertSame(
            'sales.cek-ongkir',
            app(\App\Services\MenuRegistry::class)->resolveMenuKey('sales.cek-ongkir.check'),
            'Route panel ongkir harus tetap terpetakan ke menu, kalau tidak semua pemakainya kena 403.'
        );
    }
}
