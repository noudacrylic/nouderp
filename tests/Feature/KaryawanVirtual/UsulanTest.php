<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Services\UsulanService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 2 Karyawan Virtual: mode "Minta persetujuan".
 * Yang dijaga: usulan tidak bertindak sendiri; tidak diusulkan dobel; disetujui →
 * syarat dicek ulang dulu; hanya yang berhak divisinya boleh memutus; klik kedua
 * tidak mengerjakan ulang; usulan kedaluwarsa / keadaan berubah / mode diganti tak
 * bisa disetujui; tombol Telegram berjalan; akun user virtual tak bisa login.
 */
class UsulanTest extends TestCase
{
    use RefreshDatabase;

    private const STOK = 'produksi.op-stok-menipis';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => 'TOKEN-UJI']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    }

    private function produkReady(float $stok): Product
    {
        $gudang = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $p = Product::create([
            'sku' => 'RD-' . uniqid(), 'name' => 'Produk Ready', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 50000, 'is_active' => true, 'min_stock' => 5,
        ]);
        $bom = Bom::create([
            'bom_number' => 'BOM-' . uniqid(), 'name' => 'BOM ' . $p->sku,
            'auto_production' => true, 'typical_cycles' => 1,
        ]);
        BomOutput::create([
            'bom_id' => $bom->id, 'product_id' => $p->id,
            'qty_per_cycle' => 10, 'output_type' => 'main', 'percentage' => 100,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => $stok]);

        return $p;
    }

    private function aturStok(Product $p, float $qty): void
    {
        ProductStock::where('product_id', $p->id)->update(['qty_on_hand' => $qty]);
    }

    private function opCount(Product $p): int
    {
        return ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->count();
    }

    private function kv(): KaryawanVirtualService
    {
        return app(KaryawanVirtualService::class);
    }

    private function usulan(): UsulanService
    {
        return app(UsulanService::class);
    }

    private function user(string $role = 'user', array $menus = [], ?string $chatId = null): User
    {
        $u = User::factory()->create(['role' => $role, 'is_active' => true]);
        foreach ($menus as $m) {
            $u->menuPermissions()->create(['menu_key' => $m]);
        }
        if ($chatId) $u->forceFill(['telegram_chat_id' => $chatId])->save();
        return $u->fresh();
    }

    private function kepalaProduksi(?string $chatId = '5001'): User
    {
        return $this->user('user', ['production.orders', 'karyawan-virtual'], $chatId);
    }

    /** Mode persetujuan + satu produk habis + satu kali pemeriksaan terjadwal → satu usulan. */
    private function siapkanUsulan(): array
    {
        $p = $this->produkReady(0);
        $this->kv()->ubahMode(self::STOK, 'persetujuan', null);
        $this->artisan('production:run-auto')->assertSuccessful();
        return [$p, Usulan::sole()];
    }

    // ───────────── Pengajuan ─────────────

    public function test_mode_persetujuan_mengajukan_usulan_tanpa_membuat_op(): void
    {
        $kepala = $this->kepalaProduksi('5001');
        $this->user('user', ['inventory.stocks'], '5002'); // bukan orang produksi
        [$p, $u] = $this->siapkanUsulan();

        $this->assertSame(0, $this->opCount($p), 'Persetujuan tidak boleh bertindak sebelum disetujui.');
        $this->assertSame(Usulan::MENUNGGU, $u->status);
        $this->assertSame('bom:' . Bom::sole()->id, $u->kunci);
        $this->assertTrue($u->kedaluwarsa_at->between(now()->addHours(23), now()->addHours(25)));

        $j = KaryawanVirtualJurnal::where('skill', self::STOK)->sole();
        $this->assertSame('persetujuan', $j->mode);
        $this->assertSame(1, $j->jumlah_tindakan);
        $this->assertSame('diusulkan', $j->rincian[0]['status']);
        $this->assertSame($u->id, $j->rincian[0]['usulan_id']);

        // Telegram hanya ke yang berhak divisinya, dengan tombol kv:ok / kv:no.
        $kirim = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertCount(1, $kirim);
        $this->assertSame($kepala->telegram_chat_id, (string) $kirim[0][0]['chat_id']);
        $this->assertStringContainsString("kv:ok:{$u->id}", $kirim[0][0]['reply_markup']);
        $this->assertSame([['5001', 77]], $u->telegram);
    }

    public function test_pemeriksaan_berikutnya_tidak_mengusulkan_dobel_dan_tidak_menulis_jurnal(): void
    {
        $this->kepalaProduksi();
        [, $u] = $this->siapkanUsulan();

        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(1, Usulan::count());
        $this->assertSame(1, KaryawanVirtualJurnal::where('skill', self::STOK)->count(),
            'Usulan yang masih menunggu tidak dihitung tindakan baru.');
        $this->assertSame('menunggu', $this->kv()->state(self::STOK)->rincian_terakhir[0]['status']);
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage')));
    }

    public function test_keadaan_berubah_sebelum_diputus_usulan_dibatalkan(): void
    {
        [$p, $u] = $this->siapkanUsulan();
        $this->aturStok($p, 50);

        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(Usulan::BATAL, $u->fresh()->status);
        $this->assertSame('sistem', $u->fresh()->diputus_lewat);
    }

    // ───────────── Keputusan ─────────────

    public function test_disetujui_membuat_op_sebagai_akun_virtual_dan_mencatat(): void
    {
        $kepala = $this->kepalaProduksi();
        [$p, $u] = $this->siapkanUsulan();

        $this->actingAs($kepala)
            ->post(route('karyawan-virtual.usulan.putuskan', ['manager-produksi', $u->id]), ['keputusan' => 'setuju'])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->opCount($p));
        $op = ProductionOrder::sole();
        $u->refresh();
        $this->assertSame(Usulan::DISETUJUI, $u->status);
        $this->assertSame($kepala->id, $u->diputus_oleh);
        $this->assertSame('web', $u->diputus_lewat);
        $this->assertSame($op->order_number, $u->dokumen);
        $this->assertSame(route('production.orders.show', $op->id), $u->tautan);

        $j = KaryawanVirtualJurnal::find($u->jurnal_id);
        $this->assertSame('persetujuan', $j->pemicu);
        $this->assertSame($kepala->id, $j->user_id);
        $this->assertSame('dibuat', $j->rincian[0]['status']);
        $this->assertStringContainsString("Usulan #{$u->id} disetujui oleh {$kepala->name}", $j->keterangan_pemicu);

        // Pesan Telegram diperbarui, tombol hilang.
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/editMessageText')
            && $r['message_id'] === 77 && str_contains($r['text'], 'Disetujui')));

        // Pengguna web yang menekan tombol tetap dirinya sendiri setelah skill bekerja sebagai akun virtual.
        $this->assertSame($kepala->id, auth()->id());
    }

    public function test_klik_kedua_tidak_mengerjakan_ulang(): void
    {
        $kepala = $this->kepalaProduksi();
        $lain   = $this->user('admin');
        [$p, $u] = $this->siapkanUsulan();

        $this->assertTrue($this->usulan()->putuskan($u->id, true, $kepala, 'web')['ok']);
        $kedua = $this->usulan()->putuskan($u->id, true, $lain, 'telegram');

        $this->assertFalse($kedua['ok']);
        $this->assertStringContainsString("sudah Disetujui oleh {$kepala->name}", $kedua['pesan']);
        $this->assertSame(1, $this->opCount($p));
    }

    public function test_disetujui_tapi_syarat_tak_lagi_terpenuhi_tidak_membuat_op(): void
    {
        $kepala = $this->kepalaProduksi();
        [$p, $u] = $this->siapkanUsulan();
        $this->aturStok($p, 50); // stok masuk sebelum sempat disetujui

        $hasil = $this->usulan()->putuskan($u->id, true, $kepala, 'web');

        $this->assertFalse($hasil['ok']);
        $this->assertSame(0, $this->opCount($p));
        $this->assertSame(Usulan::BATAL, $u->fresh()->status);
        $this->assertStringContainsString('Diperiksa ulang saat disetujui', $u->fresh()->catatan_hasil);
    }

    public function test_usulan_kedaluwarsa_tidak_bisa_disetujui(): void
    {
        $kepala = $this->kepalaProduksi();
        [$p, $u] = $this->siapkanUsulan();

        $this->travel(25)->hours();
        $hasil = $this->usulan()->putuskan($u->id, true, $kepala, 'web');

        $this->assertFalse($hasil['ok']);
        $this->assertSame(0, $this->opCount($p));
        $this->assertSame(Usulan::KEDALUWARSA, $u->fresh()->status);
    }

    public function test_usulan_kedaluwarsa_disapu_dan_diusulkan_ulang_bila_masih_perlu(): void
    {
        [, $u] = $this->siapkanUsulan();

        $this->travel(25)->hours();
        $this->artisan('production:run-auto')->assertSuccessful();

        $this->assertSame(Usulan::KEDALUWARSA, $u->fresh()->status);
        $this->assertSame(1, Usulan::menunggu()->count(), 'Masih menipis → diusulkan lagi.');
    }

    public function test_ditolak_tidak_membuat_op_dan_tercatat_di_jurnal(): void
    {
        $kepala = $this->kepalaProduksi();
        [$p, $u] = $this->siapkanUsulan();

        $this->assertTrue($this->usulan()->putuskan($u->id, false, $kepala, 'web')['ok']);

        $this->assertSame(0, $this->opCount($p));
        $u->refresh();
        $this->assertSame(Usulan::DITOLAK, $u->status);
        $j = KaryawanVirtualJurnal::find($u->jurnal_id);
        $this->assertSame('ditolak', $j->rincian[0]['status']);

        // Penolakan terbaca di catatan usulannya; catatan keputusannya sendiri di "Semua".
        $this->actingAs($kepala)->get(route('karyawan-virtual.show', 'manager-produksi'))
            ->assertOk()->assertSee("Usulan #{$u->id}:")->assertSee('Ditolak')->assertDontSee('❌ Ditolak');
        $this->actingAs($kepala)->get(route('karyawan-virtual.show', ['manager-produksi', 'tampil' => 'semua']))
            ->assertOk()->assertSee('❌ Ditolak')->assertSee($u->judul);
    }

    public function test_hanya_yang_berhak_divisinya_boleh_memutus(): void
    {
        $gudang = $this->user('user', ['inventory.stocks']);
        [$p, $u] = $this->siapkanUsulan();

        $this->actingAs($gudang)
            ->post(route('karyawan-virtual.usulan.putuskan', ['manager-produksi', $u->id]), ['keputusan' => 'setuju'])
            ->assertForbidden();
        $this->assertFalse($this->usulan()->putuskan($u->id, true, $gudang, 'telegram')['ok']);

        $this->assertSame(0, $this->opCount($p));
        $this->assertSame(Usulan::MENUNGGU, $u->fresh()->status);
    }

    public function test_mode_diganti_membatalkan_usulan_yang_menunggu(): void
    {
        $kepala = $this->kepalaProduksi();
        [$p, $u] = $this->siapkanUsulan();

        $this->kv()->ubahMode(self::STOK, 'nonaktif', null);

        $this->assertSame(Usulan::BATAL, $u->fresh()->status);
        $this->assertFalse($this->usulan()->putuskan($u->id, true, $kepala, 'web')['ok']);
        $this->assertSame(0, $this->opCount($p));
    }

    public function test_halaman_menampilkan_usulan_menunggu(): void
    {
        $kepala = $this->kepalaProduksi();
        [, $u] = $this->siapkanUsulan();

        $this->actingAs($kepala)->get(route('karyawan-virtual.show', 'manager-produksi'))
            ->assertOk()->assertSee('Menunggu persetujuan (1)')->assertSee($u->judul);
        $this->actingAs($kepala)->get(route('karyawan-virtual.index'))
            ->assertOk()->assertSee('1 usulan menunggu persetujuan');
    }

    public function test_jurnal_menampilkan_hasil_keputusan_dan_status_usulan_terkini(): void
    {
        $kepala = $this->kepalaProduksi();
        [, $u] = $this->siapkanUsulan();
        $this->actingAs($kepala)->get(route('karyawan-virtual.show', 'manager-produksi'))
            ->assertSee("Usulan #{$u->id}:")->assertSee('Menunggu');

        $this->actingAs($kepala)->post(route('karyawan-virtual.usulan.putuskan', ['manager-produksi', $u->id]), ['keputusan' => 'setuju']);
        $op = ProductionOrder::sole();

        // Satu usulan = satu catatan di tampilan bawaan; tabel "Usulan terakhir diputus" tak ada lagi.
        $this->actingAs($kepala)->get(route('karyawan-virtual.show', 'manager-produksi'))
            ->assertOk()
            ->assertSee("Usulan #{$u->id}:")
            ->assertSee('Disetujui')
            ->assertSee($op->order_number)
            ->assertDontSee('✅ Disetujui')
            ->assertDontSee('Usulan terakhir diputus');
        $this->actingAs($kepala)->get(route('karyawan-virtual.show', ['manager-produksi', 'tampil' => 'semua']))
            ->assertOk()
            ->assertSee('✅ Disetujui')
            ->assertDontSee('1 pemeriksaan diperiksa: 1 dibuat');
    }

    // ───────────── Telegram ─────────────

    private function klik(string $data, string $fromId)
    {
        $setting = TelegramSetting::singleton();
        $setting->forceFill(['webhook_secret' => 'rahasia-uji'])->save();

        return $this->postJson(route('telegram.webhook', 'rahasia-uji'), ['callback_query' => [
            'id' => 'cb-1', 'data' => $data, 'from' => ['id' => (int) $fromId],
            'message' => ['message_id' => 77, 'chat' => ['id' => (int) $fromId]],
        ]])->assertOk();
    }

    public function test_tombol_setujui_di_telegram(): void
    {
        $kepala = $this->kepalaProduksi('5001');
        [$p, $u] = $this->siapkanUsulan();

        $this->klik("kv:ok:{$u->id}", '5001');

        $this->assertSame(1, $this->opCount($p));
        $this->assertSame(Usulan::DISETUJUI, $u->fresh()->status);
        $this->assertSame('telegram', $u->fresh()->diputus_lewat);
        $this->assertSame($kepala->id, $u->fresh()->diputus_oleh);
    }

    public function test_tombol_telegram_dari_akun_tak_dikenal_ditolak(): void
    {
        [$p, $u] = $this->siapkanUsulan();

        $this->klik("kv:ok:{$u->id}", '9999');

        $this->assertSame(0, $this->opCount($p));
        $this->assertSame(Usulan::MENUNGGU, $u->fresh()->status);
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/answerCallbackQuery')
            && $r['show_alert'] === true));
    }

    // ───────────── Akun user virtual ─────────────

    public function test_akun_virtual_tidak_bisa_login_dan_tak_tampil_di_user_akses(): void
    {
        $akun = $this->kv()->akun('manager-produksi');
        $this->assertTrue($akun->is_virtual);
        $this->assertSame('Manager Produksi', $akun->name);
        $this->assertSame($akun->id, $this->kv()->akun('manager-produksi')->id);

        $akun->forceFill(['password' => 'sandi-diketahui'])->save();
        $this->post(route('login.attempt'), ['username' => 'kv.manager-produksi', 'password' => 'sandi-diketahui']);
        $this->assertGuest();

        $this->actingAs($this->user('super_admin'))->get(route('settings.users.index'))
            ->assertOk()->assertDontSee('kv.manager-produksi');
    }
}
