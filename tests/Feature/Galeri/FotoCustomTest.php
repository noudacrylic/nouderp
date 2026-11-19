<?php

namespace Tests\Feature\Galeri;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Galeri\Models\GaleriFoto;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Galeri Tahap 2: OP custom selesai → task foto, unggahan tertaut pesanan menyelesaikan task,
 * pengingat di kartu packing, dan pencarian referensi dari chat.
 */
class FotoCustomTest extends TestCase
{
    use RefreshDatabase;

    private string $kerja;
    private string $root;
    private User $admin;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kerja = sys_get_temp_dir() . '/galeri-foto-' . uniqid();
        $this->root = $this->kerja . '/nas';
        File::makeDirectory($this->root, 0775, true);
        config(['galeri.root' => $this->root, 'galeri.wajib_mount' => false,
            'galeri.thumb_dir' => $this->kerja . '/thumb', 'galeri.tmp_dir' => $this->kerja . '/tmp']);

        $this->admin = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
        $this->admin->menuPermissions()->create(['menu_key' => 'crm.galeri']);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->admin->id, 'urutan' => 1]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->kerja);
        parent::tearDown();
    }

    private function nyalakan(string $mode = 'otomatis'): void
    {
        MejaKerjaSkill::updateOrCreate(['skill' => 'foto-custom'], ['peran' => 'desain', 'bobot' => 7, 'sla_menit' => 240]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => 'admin.foto-custom'], ['mode' => $mode, 'mode_diubah_at' => now()->subDay()]);
    }

    private function so(string $sku = 'CS2', string $keterangan = 'Akrilik Sign 20x5'): SalesOrder
    {
        $this->nomor++;
        $p = Product::firstOrCreate(['sku' => $sku], ['name' => 'Produk ' . $sku, 'sale_type' => 'preorder', 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true]);
        $so = SalesOrder::create(['order_number' => 'SO-F' . $this->nomor,
            'customer_id' => Customer::create(['code' => 'CF' . $this->nomor, 'name' => 'Pelanggan', 'is_active' => true])->id,
            'warehouse_id' => Warehouse::create(['name' => 'Gudang F' . $this->nomor, 'is_sellable' => true, 'is_active' => true])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => $keterangan, 'qty' => 2, 'unit_price' => 1000,
            'net_unit_price' => 1000, 'line_subtotal' => 2000, 'line_discount' => 0, 'line_total' => 2000]);

        return $so;
    }

    private function op(SalesOrder $so, string $status = 'finalized', string $tipe = 'custom', $selesai = null): void
    {
        DB::table('production_orders')->insert(['order_number' => 'OP-F' . (++$this->nomor), 'type' => $tipe, 'sales_order_id' => $so->id, 'warehouse_id' => $so->warehouse_id,
            'status' => $status, 'production_date' => now()->toDateString(), 'finalized_at' => $status === 'finalized' ? ($selesai ?? now()) : null,
            'created_at' => now(), 'updated_at' => $selesai ?? now()]);
    }

    private function unggah(string $nama, string $folder = '', ?int $so = null)
    {
        return $this->actingAs($this->admin)->postJson(route('cs.galeri.unggah'), array_filter([
            'id' => md5($nama . $folder), 'offset' => 0, 'total' => 3, 'nama' => $nama, 'folder' => $folder, 'so' => $so,
        ], fn ($v) => $v !== null) + ['potongan' => UploadedFile::fake()->createWithContent('potongan', 'abc')]);
    }

    public function test_op_custom_selesai_jadi_task_lalu_selesai_saat_foto_tertaut(): void
    {
        $this->nyalakan();
        $so = $this->so();
        $meja = app(MejaKerjaService::class);

        // Belum selesai produksi → belum ada task.
        $this->op($so, 'in_progress');
        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', 'foto-custom')->count());

        $this->op($so);
        $meja->sinkron();
        $meja->sinkron();
        $t = Task::where('skill', 'foto-custom')->sole();
        $this->assertSame('desain', $t->peran);
        $this->assertSame('open', $t->status);
        $this->assertStringContainsString('Akrilik Sign 20x5', $t->description);
        $this->assertSame('porto', $meja->skill('foto-custom')->formKartu($t)['jenis'], 'Kartu foto: tombol lampirkan dari Kotak Porto.');

        // Cadangan dari HP: Galeri mode pesanan langsung membuka folder porto otomatis
        // (custom → porto/<nama produk di SO>), unggahan selalu masuk ke sana.
        $jawab = fn (string $k) => $this->actingAs($this->admin)->post(route('tasks.meja.jawab', $t->id), ['jawaban' => $k]);
        $jawab('foto-selesai')->assertSessionHas('error');
        $folder = 'porto/Akrilik Sign 20x5';
        $this->actingAs($this->admin)->get(route('cs.galeri', ['so' => $so->id]))->assertRedirect(route('cs.galeri', ['p' => $folder]));
        $this->assertDirectoryExists($this->root . '/' . $folder);
        $this->get(route('cs.galeri', ['p' => $folder]))->assertOk()->assertSee('Foto untuk ' . $so->order_number);

        $this->unggah('hasil.jpg', 'Lain', $so->id)->assertOk()->assertJson(['selesai' => true]);
        $this->assertSame([$folder . '/hasil.jpg'], GaleriFoto::where('sales_order_id', $so->id)->pluck('path')->all());
        $this->post(route('cs.galeri.foto-utama'), ['p' => $folder . '/hasil.jpg'])->assertSessionHas('success');
        $this->assertSame($folder . '/hasil.jpg', \App\Modules\Galeri\Models\GaleriKarya::where('sales_order_id', $so->id)->value('foto_utama'));

        $meja->sinkron();
        $this->assertSame('open', $t->fresh()->status, 'Tak selesai sendiri — admin menekan Foto selesai.');
        $jawab('foto-selesai')->assertSessionHas('success');
        $this->assertSame('done', $t->fresh()->status);

        // Selesai: mode pesanan berakhir.
        $this->actingAs($this->admin)->get(route('cs.galeri', ['so' => 0]))->assertOk()->assertDontSee('Foto untuk');
    }

    public function test_op_dengan_langkah_tempel_difoto_di_kartu_cetak_bukan_foto_custom(): void
    {
        $this->nyalakan();
        $so = $this->so();
        $this->op($so, 'completed');
        $opId = (int) DB::table('production_orders')->where('sales_order_id', $so->id)->value('id');
        DB::table('production_order_steps')->insert(['production_order_id' => $opId, 'step_number' => 1, 'name' => 'Desain — cetak & tempel stiker',
            'desain' => 'tempel', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        app(MejaKerjaService::class)->sinkron();
        $this->assertSame(0, Task::where('skill', 'foto-custom')->count());
    }

    public function test_hanya_sku_custom_yang_baru_selesai_dan_batal_saat_terkirim(): void
    {
        $this->nyalakan();
        $meja = app(MejaKerjaService::class);

        $this->op($this->so('BC-2x2'));                                      // produk standar
        $this->op($this->so('CS1'), 'finalized', 'custom', now()->subDays(3)); // selesai sebelum skill nyala
        $this->op($this->so('CS3'), 'finalized', 'ready_stock');             // bukan OP custom
        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', 'foto-custom')->count());

        $so = $this->so('CS4');
        $this->op($so);
        $meja->sinkron();
        $t = Task::where('skill', 'foto-custom')->sole();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('sales_deliveries')->insert(['delivery_number' => 'SJ-F', 'sales_order_id' => $so->id, 'warehouse_id' => $so->warehouse_id, 'delivery_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $meja->sinkron();
        $this->assertSame('cancelled', $t->fresh()->status);
    }

    public function test_mode_bayangan_tidak_membuat_task_dan_tanpa_pengingat(): void
    {
        $this->nyalakan('bayangan');
        $so = $this->so();
        $this->op($so);
        app(MejaKerjaService::class)->sinkron();

        $this->assertSame(0, Task::where('skill', 'foto-custom')->where('id', '>', 0)->count());
        $this->assertSame([], app(FotoProdukService::class)->pengingat([$so->id]));
    }

    public function test_pengingat_di_kartu_packing_hilang_setelah_difoto(): void
    {
        $this->nyalakan();
        $so = $this->so();
        $this->op($so);
        $this->actingAs($this->admin);
        $baris = function () use ($so) {
            $m = new \ReflectionMethod(FulfillmentReadinessService::class, 'rowsFor');

            return $m->invoke(app(FulfillmentReadinessService::class), collect([$so->fresh()]))->first();
        };

        $r = $baris();
        $this->assertTrue($r['belum_difoto']);
        $html = view('erp.pos.fulfillment._so_card', ['row' => $r, 'mode' => 'perlu_diproses'])->render();
        $this->assertStringContainsString('Belum difoto', $html);
        $this->assertStringContainsString(route('cs.galeri', ['so' => $so->id]), $html);

        app(FotoProdukService::class)->tautkan('a.jpg', $so->id);
        $this->assertFalse($baris()['belum_difoto']);
    }

    public function test_pengingat_packing_juga_untuk_ks_bc_dan_tanpa_tombol_sudah_dikirim(): void
    {
        $this->nyalakan();
        $foto = app(FotoProdukService::class);
        $ks = $this->so('KS-K3-M4');
        $bc = $this->so('BC-3x1');
        $nm = $this->so('NM-A-TXT');
        $lama = $this->so('KS-K3-M4');
        $lama->forceFill(['created_at' => now()->subDays(3)])->save(); // sebelum foto ditugaskan

        $this->assertEqualsCanonicalizing([$ks->id, $bc->id], array_keys($foto->pengingat([$ks->id, $bc->id, $nm->id, $lama->id])));

        $foto->tandaiTanpaFoto($bc->id, 'HP/kamera bermasalah');
        $this->assertSame([$ks->id], array_keys($foto->pengingat([$ks->id, $bc->id])));

        $kunci = array_column(app(MejaKerjaService::class)->skill('foto-custom')->jawaban(new Task(['meta' => ['tahap' => 'foto']])), 'kunci');
        $this->assertSame(['foto-selesai'], $kunci, 'Tanpa foto hanya lewat ⏭ Lewati foto beralasan.');
        $this->assertNotContains('Barang sudah dikirim sebelum difoto', config('galeri.alasan_lewati_foto'), 'Pengiriman terdeteksi sendiri.');

        // KS tanpa stiker: dilewati dengan alasan → kartu selesai & lencana packing hilang.
        $t = Task::create(['title' => 'Foto KS', 'skill' => 'foto-custom', 'peran' => 'desain', 'status' => 'open', 'source' => 'event', 'creator_user_id' => $this->admin->id,
            'taskable_type' => SalesOrder::class, 'taskable_id' => $ks->id, 'meta' => ['tahap' => 'foto']]);
        $this->actingAs($this->admin)->post(route('tasks.meja.porto.lewati', $t->id), ['alasan' => 'Tanpa stiker / polos — tidak perlu difoto'])->assertSessionHas('success');
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame([], $foto->pengingat([$ks->id]));
    }

    public function test_ganti_nama_pindah_hapus_menjaga_tautan(): void
    {
        $so = $this->so();
        $this->actingAs($this->admin)->post(route('cs.galeri.folder.store'), ['p' => '', 'nama' => 'Lini'])->assertRedirect();
        $this->unggah('a.jpg', 'Lini')->assertOk();
        app(FotoProdukService::class)->tautkan('Lini/a.jpg', $so->id);
        $path = fn () => GaleriFoto::where('sales_order_id', $so->id)->value('path');

        $this->post(route('cs.galeri.ganti-nama'), ['p' => 'Lini/a.jpg', 'nama' => 'b'])->assertSessionHas('success');
        $this->assertSame('Lini/b.jpg', $path());

        // Folder induk berganti nama: isinya ikut. "Lini" tak boleh menyeret "Lini 2".
        $lain = $this->so();
        $this->post(route('cs.galeri.folder.store'), ['p' => '', 'nama' => 'Lini 2']);
        $this->unggah('c.jpg', 'Lini 2')->assertOk();
        app(FotoProdukService::class)->tautkan('Lini 2/c.jpg', $lain->id);
        $this->post(route('cs.galeri.ganti-nama'), ['p' => 'Lini', 'nama' => 'Baru'])->assertSessionHas('success');
        $this->assertSame('Baru/b.jpg', $path());
        $this->assertSame('Lini 2/c.jpg', GaleriFoto::where('sales_order_id', $lain->id)->value('path'));

        $this->post(route('cs.galeri.pindah'), ['p' => 'Baru/b.jpg', 'tujuan' => 'Lini 2'])->assertSessionHas('success');
        $this->assertSame('Lini 2/b.jpg', $path());

        $this->post(route('cs.galeri.hapus'), ['p' => 'Lini 2/b.jpg'])->assertSessionHas('success');
        $this->assertNull($path());
    }

    public function test_tautkan_berkas_yang_sudah_ada(): void
    {
        $so = $this->so();
        file_put_contents($this->root . '/lama.jpg', 'x');

        $this->actingAs($this->admin)->post(route('cs.galeri.tautkan'), ['p' => 'lama.jpg'])->assertSessionHas('error');
        $this->assertSame(0, GaleriFoto::count());

        $this->get(route('cs.galeri', ['so' => $so->id]))->assertRedirect(); // → folder porto pesanan
        $this->get(route('cs.galeri'))->assertOk()->assertSee('Tautkan');
        $this->post(route('cs.galeri.tautkan'), ['p' => 'lama.jpg'])->assertSessionHas('success');
        $this->post(route('cs.galeri.tautkan'), ['p' => 'lama.jpg'])->assertSessionHas('success');
        $this->assertSame(1, GaleriFoto::where('sales_order_id', $so->id)->count());
        $this->post(route('cs.galeri.tautkan'), ['p' => 'tidak-ada.jpg'])->assertSessionHas('error');
    }

    public function test_tab_foto_chat_menelusuri_folder_nas_hanya_lihat(): void
    {
        Cache::flush();
        $cs = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $cs->menuPermissions()->create(['menu_key' => 'crm.inbox']);
        $orangLain = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $so = $this->so('CS5', 'Plakat Wisuda Biru');
        File::makeDirectory($this->root . '/Tempat Brosur/TBKD-A4', 0775, true);
        File::makeDirectory($this->root . '/#recycle', 0775, true);
        file_put_contents($this->root . '/plakat.jpg', 'x');
        file_put_contents($this->root . '/Tempat Brosur/TBKD-A4/depan.jpg', 'x');
        file_put_contents($this->root . '/Tempat Brosur/harga.xlsx', 'x');
        file_put_contents($this->root . '/#recycle/buang.jpg', 'x');
        app(FotoProdukService::class)->tautkan('plakat.jpg', $so->id);
        app(FotoProdukService::class)->tautkan('sudah-dipindah.jpg', $so->id);
        $cari = fn (array $q) => $this->actingAs($cs)->getJson(route('crm.inbox.foto.cari', $q))->assertOk()->json();

        // Akar: folder lini + foto lepas; foto custom tertaut tampil terpisah dengan barangnya.
        $akar = $cari([]);
        $this->assertSame(['Tempat Brosur'], array_column($akar['subfolder'], 'nama'), '#recycle tersembunyi.');
        $this->assertSame(['plakat.jpg'], array_column($akar['foto'], 'path'));
        $this->assertSame('Plakat Wisuda Biru', $akar['custom'][0]['barang'], 'Berkas yang sudah tak ada dilewati.');

        $lini = $cari(['folder' => 'Tempat Brosur']);
        $this->assertSame(['TBKD-A4'], array_column($lini['subfolder'], 'nama'));
        $this->assertSame([], $lini['foto'], 'xlsx tidak ditampilkan.');

        // Cari: nama folder/berkas di seluruh NAS + nomor pesanan/SKU/barang untuk foto custom.
        $this->assertSame(['Tempat Brosur/TBKD-A4/depan.jpg'], array_column($cari(['q' => 'tbkd'])['foto'], 'path'));
        $this->assertSame(['Tempat Brosur/TBKD-A4'], array_column($cari(['q' => 'tbkd'])['subfolder'], 'path'));
        foreach (['wisuda', 'CS5', $so->order_number, 'plakat'] as $q) {
            $this->assertSame(['plakat.jpg'], array_column($cari(['q' => $q])['foto'], 'path'), "cari: $q");
        }
        $this->assertSame([], $cari(['q' => 'buang'])['foto']);

        $this->actingAs($cs)->get(route('crm.inbox.foto.berkas', ['p' => 'Tempat Brosur/TBKD-A4/depan.jpg']))->assertOk();
        $this->actingAs($cs)->get(route('crm.inbox.foto.berkas', ['p' => 'Tempat Brosur/harga.xlsx']))->assertNotFound();
        $this->actingAs($cs)->get(route('crm.inbox.foto.berkas', ['p' => '#recycle/buang.jpg']))->assertNotFound();
        $this->actingAs($cs)->get(route('crm.inbox.foto.berkas', ['p' => '../../etc/passwd.jpg']))->assertNotFound();
        $this->actingAs($cs)->get(route('cs.galeri.berkas', ['p' => 'plakat.jpg']))->assertForbidden();
        $this->actingAs($orangLain)->get(route('crm.inbox.foto.berkas', ['p' => 'plakat.jpg']))->assertForbidden();
    }
}
