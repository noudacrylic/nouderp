<?php

namespace Tests\Feature\Galeri;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Galeri\Models\GaleriFoto;
use App\Modules\Galeri\Models\GaleriKarya;
use App\Modules\Galeri\Models\GaleriKotak;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Galeri\Services\KotakPortoService;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Kotak Porto (17 Nov 2026): foto dari galeri HP → ditampung utuh di server → dilampirkan dari
 * kartu Meja (laptop) → pindah ke folder porto pesanan di NAS, kartu selesai.
 */
class KotakPortoTest extends TestCase
{
    use RefreshDatabase;

    private string $kerja;
    private string $root;
    private User $farid;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kerja = sys_get_temp_dir() . '/kotak-porto-' . uniqid();
        $this->root = $this->kerja . '/nas';
        File::makeDirectory($this->root, 0775, true);
        config(['galeri.root' => $this->root, 'galeri.wajib_mount' => false, 'galeri.thumb_dir' => $this->kerja . '/thumb',
            'galeri.tmp_dir' => $this->kerja . '/tmp', 'galeri.kotak_dir' => $this->kerja . '/kotak']);

        $this->farid = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
        $this->farid->menuPermissions()->create(['menu_key' => 'crm.galeri']);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->farid->id, 'urutan' => 1]);
        MejaKerjaSkill::updateOrCreate(['skill' => 'desain'], ['peran' => 'desain', 'bobot' => 8, 'sla_menit' => 120]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => 'admin.desain'], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->kerja);
        parent::tearDown();
    }

    private function so(string $sku, string $keterangan = ''): SalesOrder
    {
        $this->nomor++;
        $p = Product::firstOrCreate(['sku' => $sku], ['name' => 'Produk ' . $sku, 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
        $so = SalesOrder::create(['order_number' => 'SO/2026/11/' . str_pad((string) $this->nomor, 5, '0', STR_PAD_LEFT),
            'customer_id' => Customer::create(['code' => 'CK' . $this->nomor, 'name' => 'Pelanggan', 'is_active' => true])->id,
            'warehouse_id' => Warehouse::create(['name' => 'Gudang K' . $this->nomor, 'is_sellable' => true, 'is_active' => true])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => $keterangan ?: null, 'qty' => 1, 'unit_price' => 1,
            'net_unit_price' => 1, 'line_subtotal' => 1, 'line_discount' => 0, 'line_total' => 1]);
        return $so;
    }

    /** Foto JPEG sungguhan (GD) — lebar × tinggi. */
    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($img, 255, 0, 0));
        ob_start();
        imagejpeg($img, null, 95);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Unggah dari HP lewat menu Porto (potongan tunggal). */
    private function unggah(string $nama, string $isi): GaleriKotak
    {
        $this->actingAs($this->farid)->postJson(route('cs.porto.unggah'), [
            'id' => md5($nama . microtime()), 'offset' => 0, 'total' => strlen($isi), 'nama' => $nama,
            'potongan' => UploadedFile::fake()->createWithContent('potongan', $isi),
        ])->assertOk()->assertJson(['selesai' => true]);

        return GaleriKotak::latest('id')->firstOrFail();
    }

    /** Kartu Desain pesanan sampai tahap foto. */
    private function kartuFoto(SalesOrder $so): Task
    {
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'desain')->where('taskable_id', $so->id)->sole();
        $this->actingAs($this->farid)->post(route('tasks.meja.mulai', $t->id));
        foreach (['desain-ok', 'cetak-ok'] as $k) {
            $this->post(route('tasks.meja.jawab', $t->id), ['jawaban' => $k])->assertSessionHas('success');
        }
        $this->assertSame('foto', $t->fresh()->meta['tahap']);
        return $t->fresh();
    }

    public function test_unggah_dari_hp_lalu_lampirkan_ks_ke_folder_lini_porto_sku(): void
    {
        $this->actingAs($this->farid)->get(route('cs.porto'))->assertOk()->assertSee('Kotak kosong');
        $isi = $this->jpeg(40, 20);
        $foto = $this->unggah('IMG_1.jpg', $isi);
        $video = $this->unggah('VID_1.mp4', 'video');
        $this->assertSame($isi, file_get_contents($foto->abs()), 'Disimpan utuh tanpa kompresi.');
        $this->get(route('cs.porto'))->assertOk()->assertSee(route('cs.porto.thumb', $foto->id), false);

        $so = $this->so('KS-K3-M4');
        $t = $this->kartuFoto($so);
        $this->assertSame('porto', app(MejaKerjaService::class)->skill('desain')->formKartu($t)['jenis']);
        $this->get(route('tasks.meja.index'))->assertOk()->assertSee('Gambar belum dilampirkan');

        $daftar = $this->getJson(route('tasks.meja.porto.daftar'))->assertOk()->json('items');
        $this->assertEqualsCanonicalizing([$foto->id, $video->id], array_column($daftar, 'id'));

        // Video saja ditolak — wajib ada foto.
        $this->postJson(route('tasks.meja.porto.lampirkan', $t->id), ['pilihan' => [['id' => $video->id]]])->assertStatus(422);

        $this->postJson(route('tasks.meja.porto.lampirkan', $t->id), ['pilihan' => [['id' => $foto->id], ['id' => $video->id]]])->assertOk();

        $folder = 'Kotak Saran/porto/KS-K3-M4';
        $this->assertSame('done', $t->fresh()->status);
        $this->assertEqualsCanonicalizing([$folder . '/SO-2026-11-00001.jpg', $folder . '/SO-2026-11-00001.mp4'],
            GaleriFoto::where('sales_order_id', $so->id)->pluck('path')->all());
        // Tanpa edit → dipotong tengah ke 4:5 (40×20 → 16×20); asli tetap disimpan utuh.
        $this->assertSame([16, 20], array_slice(getimagesize($this->root . '/' . $folder . '/SO-2026-11-00001.jpg'), 0, 2));
        $this->assertSame($isi, file_get_contents($this->root . '/' . $folder . '/asli/SO-2026-11-00001.jpg'));
        $this->assertFileDoesNotExist($foto->abs(), 'Dipindah, bukan disalin.');
        $this->assertSame($folder . '/SO-2026-11-00001.jpg', GaleriKarya::where('sales_order_id', $so->id)->value('foto_utama'));
        $this->assertNotNull($foto->fresh()->dipakai_at);
        $this->assertSame([], $this->getJson(route('tasks.meja.porto.daftar'))->json('items'));

        // Pesanan KS lain: folder SKU yang sama dipakai bersama.
        $so2 = $this->so('KS-K3-M4');
        $t2 = $this->kartuFoto($so2);
        $serasio = $this->jpeg(40, 50);
        $f2 = $this->unggah('IMG_2.jpg', $serasio);
        $this->postJson(route('tasks.meja.porto.lampirkan', $t2->id), ['pilihan' => [['id' => $f2->id]]])->assertOk();
        $this->assertSame([$folder . '/SO-2026-11-00002.jpg'], GaleriFoto::where('sales_order_id', $so2->id)->pluck('path')->all());
        $this->assertSame($serasio, file_get_contents($this->root . '/' . $folder . '/SO-2026-11-00002.jpg'), 'Sudah 4:5 & tegak → tak disentuh.');
        $this->assertFileDoesNotExist($this->root . '/' . $folder . '/asli/SO-2026-11-00002.jpg');

        // Berkas yang sudah dipakai tidak bisa dilampirkan lagi.
        $so3 = $this->so('KS-K3-M4');
        $t3 = $this->kartuFoto($so3);
        $this->postJson(route('tasks.meja.porto.lampirkan', $t3->id), ['pilihan' => [['id' => $f2->id]]])->assertStatus(422);
    }

    public function test_edit_potong_putar_menyimpan_hasil_dan_asli(): void
    {
        $so = $this->so('BC-3x1');
        $t = $this->kartuFoto($so);
        $foto = $this->unggah('IMG_1.jpg', $this->jpeg(40, 20));

        // Pratinjau sudah diputar: 40×20 → 20×40.
        $prev = $this->get(route('tasks.meja.porto.pratinjau', ['kotak' => $foto->id, 'putar' => 90]))->assertOk();
        [$pw, $ph] = getimagesize($prev->getFile()->getPathname());
        $this->assertSame([20, 40], [$pw, $ph]);

        $this->postJson(route('tasks.meja.porto.lampirkan', $t->id), ['pilihan' => [
            ['id' => $foto->id, 'putar' => 90, 'potong' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.5]],
        ]])->assertOk();

        $hasil = $this->root . '/BC/porto/BC-3x1/SO-2026-11-00001.jpg';
        $this->assertSame([20, 20], array_slice(getimagesize($hasil), 0, 2), 'Diputar lalu dipotong setengah atas.');
        $this->assertSame([40, 20], array_slice(getimagesize($this->root . '/BC/porto/BC-3x1/asli/SO-2026-11-00001.jpg'), 0, 2), 'Asli tetap disimpan.');
    }

    public function test_custom_ke_porto_nama_produk_dan_nama_bentrok_diberi_angka(): void
    {
        $svc = app(FotoProdukService::class);
        $a = $this->so('CS2', 'Plakat Wisuda Biru');
        $b = $this->so('CS2', 'Plakat Wisuda Biru');

        $this->assertSame('porto/Plakat Wisuda Biru', $svc->folderPorto($a->id));
        $this->assertSame('porto/Plakat Wisuda Biru', $svc->folderPorto($a->id), 'Pesanan yang sama memakai foldernya lagi.');
        $this->assertSame('porto/Plakat Wisuda Biru 2', $svc->folderPorto($b->id));
        $this->assertDirectoryExists($this->root . '/porto/Plakat Wisuda Biru 2');
    }

    public function test_sapu_membuang_kotak_lama_yang_tak_dipakai(): void
    {
        $lama = $this->unggah('IMG_1.jpg', 'x');
        $baru = $this->unggah('IMG_2.jpg', 'y');
        GaleriKotak::whereKey($lama->id)->update(['created_at' => now()->subDays(15)]);

        $this->assertSame(1, app(KotakPortoService::class)->sapu());
        $this->assertNull($lama->fresh());
        $this->assertFileDoesNotExist($lama->abs());
        $this->assertNotNull($baru->fresh());
    }

    public function test_tanpa_izin_galeri_dan_bukan_anggota_meja_ditolak(): void
    {
        $orang = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
        $k = $this->unggah('IMG_1.jpg', 'x');
        $this->actingAs($orang)->get(route('cs.porto'))->assertForbidden();
        $this->actingAs($orang)->get(route('tasks.meja.porto.thumb', $k->id))->assertForbidden();
        $this->actingAs($orang)->getJson(route('tasks.meja.porto.daftar'))->assertForbidden();
    }
}
