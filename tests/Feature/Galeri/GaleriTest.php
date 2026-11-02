<?php

namespace Tests\Feature\Galeri;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Galeri PWA: folder NAS adalah sumber kebenaran. Yang dijaga: hanya akun
 * ber-izin crm.galeri, tak bisa keluar root lewat "../", folder sistem Synology
 * tak tampil, unggahan bertahap utuh & tahan putus, hapus = pindah ke #recycle,
 * dan tak ada tulisan sama sekali saat NAS tidak ter-mount.
 */
class GaleriTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $kerja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kerja = sys_get_temp_dir() . '/galeri-test-' . uniqid();
        $this->root = $this->kerja . '/nas';
        File::makeDirectory($this->root, 0775, true);
        config([
            'galeri.root'        => $this->root,
            'galeri.wajib_mount' => false,
            'galeri.thumb_dir'   => $this->kerja . '/thumb',
            'galeri.tmp_dir'     => $this->kerja . '/tmp',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->kerja);
        parent::tearDown();
    }

    private function akun(bool $galeri = true): User
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
        if ($galeri) $u->menuPermissions()->create(['menu_key' => 'crm.galeri']);
        return $u->fresh();
    }

    private function potong(User $u, string $id, int $offset, int $total, string $isi, string $nama = 'produk.jpg', string $folder = '')
    {
        return $this->actingAs($u)->postJson(route('cs.galeri.unggah'), [
            'id' => $id, 'offset' => $offset, 'total' => $total, 'nama' => $nama, 'folder' => $folder,
            'potongan' => UploadedFile::fake()->createWithContent('potongan', $isi),
        ]);
    }

    public function test_tanpa_izin_galeri_ditolak_dan_menu_tidak_tampil(): void
    {
        $cs = $this->akun(false);
        $this->actingAs($cs)->get(route('cs.galeri'))->assertForbidden();
        $this->actingAs($cs)->get(route('cs.profil'))->assertOk()->assertDontSee(route('cs.galeri'), false);

        $admin = $this->akun();
        $this->actingAs($admin)->get(route('cs.profil'))->assertOk()->assertSee(route('cs.galeri'), false);
    }

    public function test_daftar_menyembunyikan_folder_sistem_dan_urut_nama(): void
    {
        foreach (['Tent Holder', 'BC', 'AM - Akrilik Mahar', '#recycle', '@eaDir', '.SynologyWorkingDirectory'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        file_put_contents($this->root . '/DSC_1927.jpg', 'x');
        file_put_contents($this->root . '/Thumbs.db', 'x');
        file_put_contents($this->root . '/~$catatan.docx', 'x');

        $this->actingAs($this->akun())->get(route('cs.galeri'))->assertOk()
            ->assertSeeInOrder(['AM - Akrilik Mahar', 'BC', 'Tent Holder'])
            ->assertSee('1 berkas')
            // #recycle disebut di teks konfirmasi hapus, jadi yang diperiksa daftar foldernya.
            ->assertViewHas('folder', fn ($f) => ! in_array('#recycle', array_column($f, 'nama'), true))
            ->assertDontSee('@eaDir')->assertDontSee('SynologyWorkingDirectory')->assertDontSee('Thumbs.db');
    }

    public function test_tidak_bisa_keluar_root(): void
    {
        file_put_contents($this->kerja . '/rahasia.jpg', 'x');
        $u = $this->akun();

        $this->actingAs($u)->get(route('cs.galeri', ['p' => '../']))->assertRedirect(route('cs.galeri'));
        $this->actingAs($u)->get(route('cs.galeri.berkas', ['p' => '../rahasia.jpg']))->assertNotFound();
        $this->actingAs($u)->get(route('cs.galeri.berkas', ['p' => '#recycle/x.jpg']))->assertNotFound();
        $this->potong($u, 'unggah-keluar', 0, 3, 'abc', 'a.jpg', '../')->assertStatus(422);
        $this->assertFileDoesNotExist($this->kerja . '/a.jpg');
    }

    public function test_unggah_bertahap_utuh_dan_nama_bentrok_diberi_nomor(): void
    {
        mkdir($this->root . '/BC');
        file_put_contents($this->root . '/BC/produk.jpg', 'lama');
        $u = $this->akun();

        $this->potong($u, 'unggah-satu', 0, 6, 'abc', 'produk.jpg', 'BC')
            ->assertOk()->assertJson(['selesai' => false, 'diterima' => 3]);
        // Berkas setengah jadi belum boleh muncul di folder bersama.
        $this->assertFileDoesNotExist($this->root . '/BC/produk (2).jpg');

        $this->potong($u, 'unggah-satu', 3, 6, 'def', 'produk.jpg', 'BC')
            ->assertOk()->assertJson(['selesai' => true, 'path' => 'BC/produk (2).jpg']);

        $this->assertSame('abcdef', file_get_contents($this->root . '/BC/produk (2).jpg'));
        $this->assertSame('lama', file_get_contents($this->root . '/BC/produk.jpg'));
    }

    public function test_potongan_tidak_berurutan_minta_lanjut_dari_yang_diterima(): void
    {
        $u = $this->akun();
        $this->potong($u, 'unggah-dua', 0, 9, 'abc', 'v.mp4')->assertOk();

        // HP mengira potongan kedua gagal padahal belum terkirim → loncat ke 6.
        $this->potong($u, 'unggah-dua', 6, 9, 'ghi', 'v.mp4')
            ->assertStatus(409)->assertJson(['diterima' => 3]);

        $this->potong($u, 'unggah-dua', 3, 9, 'def', 'v.mp4')->assertOk();
        $this->potong($u, 'unggah-dua', 6, 9, 'ghi', 'v.mp4')->assertOk()->assertJson(['selesai' => true]);
        $this->assertSame('abcdefghi', file_get_contents($this->root . '/v.mp4'));
    }

    public function test_hanya_foto_dan_video(): void
    {
        $this->potong($this->akun(), 'unggah-tiga', 0, 3, 'abc', 'skrip.php')
            ->assertStatus(422)->assertJsonFragment(['pesan' => '"skrip.php" bukan foto/video.']);
    }

    public function test_folder_ganti_nama_pindah_dan_hapus_ke_recycle(): void
    {
        $u = $this->akun();

        $this->actingAs($u)->post(route('cs.galeri.folder.store'), ['p' => '', 'nama' => 'Custom'])
            ->assertRedirect(route('cs.galeri', ['p' => 'Custom']));
        $this->assertDirectoryExists($this->root . '/Custom');

        file_put_contents($this->root . '/IMG_1.jpg', 'x');
        // Ekstensi dipertahankan walau tidak ditulis.
        $this->actingAs($u)->post(route('cs.galeri.ganti-nama'), ['p' => 'IMG_1.jpg', 'nama' => 'Tent biru', 'kembali' => ''])
            ->assertRedirect(route('cs.galeri'))->assertSessionHas('success');
        $this->assertFileExists($this->root . '/Tent biru.jpg');

        $this->actingAs($u)->post(route('cs.galeri.pindah'), ['p' => 'Tent biru.jpg', 'tujuan' => 'Custom', 'kembali' => ''])
            ->assertSessionHas('success');
        $this->assertFileExists($this->root . '/Custom/Tent biru.jpg');

        // Folder tak bisa dipindah ke dalam dirinya sendiri.
        mkdir($this->root . '/Custom/Sub');
        $this->actingAs($u)->post(route('cs.galeri.pindah'), ['p' => 'Custom', 'tujuan' => 'Custom/Sub'])
            ->assertSessionHas('error');

        $this->actingAs($u)->post(route('cs.galeri.hapus'), ['p' => 'Custom/Tent biru.jpg', 'kembali' => 'Custom'])
            ->assertRedirect(route('cs.galeri', ['p' => 'Custom']));
        $this->assertFileDoesNotExist($this->root . '/Custom/Tent biru.jpg');
        $this->assertFileExists($this->root . '/#recycle/Custom/Tent biru.jpg');
    }

    public function test_nas_tidak_ter_mount_menolak_semua_tulisan(): void
    {
        // Folder temp dan induknya berada di perangkat yang sama = "mount putus".
        config(['galeri.wajib_mount' => true]);
        $u = $this->akun();

        $this->actingAs($u)->get(route('cs.galeri'))->assertOk()->assertSee('NAS tidak tersambung');
        $this->actingAs($u)->post(route('cs.galeri.folder.store'), ['p' => '', 'nama' => 'Baru'])->assertSessionHas('error');
        $this->potong($u, 'unggah-empat', 0, 3, 'abc')->assertStatus(422);
        $this->assertDirectoryDoesNotExist($this->root . '/Baru');
        $this->assertFileDoesNotExist($this->root . '/produk.jpg');
    }

    public function test_thumbnail_foto_dibuat_dan_dicache(): void
    {
        UploadedFile::fake()->image('a.jpg', 2000, 1000)->move($this->root, 'a.jpg');
        $u = $this->akun();

        $this->actingAs($u)->get(route('cs.galeri.thumb', ['p' => 'a.jpg']))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $thumbs = File::allFiles($this->kerja . '/thumb');
        $this->assertCount(1, $thumbs);
        [$w, $h] = getimagesize($thumbs[0]->getPathname());
        $this->assertSame([480, 240], [$w, $h]);

        // Bukan gambar yang bisa dibaca GD → 404 (HP menampilkan ikon).
        file_put_contents($this->root . '/b.heic', 'bukan gambar');
        $this->actingAs($u)->get(route('cs.galeri.thumb', ['p' => 'b.heic']))->assertNotFound();
    }
}
