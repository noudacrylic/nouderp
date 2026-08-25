<?php

namespace Tests\Feature\Store;

use App\Models\StoreTutorial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editor "Langkah Bergambar" (Trix) — dua kehilangan sunyi yang pernah terjadi
 * dan sama-sama tidak menampilkan pesan galat apa pun:
 *
 *   1. Heading yang dipilih admin balik jadi teks biasa setiap kali halaman
 *      dibuka ulang, karena setelan Trix dipasang SESUDAH pustakanya jalan.
 *      Pustaka itu menghafal daftar nama tag blok pada parse pertama, jadi
 *      <h2> di naskah tersimpan tidak pernah lagi dikenali sebagai heading.
 *
 *   2. Gambar hilang setelah disimpan, karena naskahnya sempat tersimpan saat
 *      unggahannya belum selesai — lampirannya masih ber-alamat `blob:`, yang
 *      mati begitu tab ditutup.
 *
 * Keduanya tak terlihat dari layar sampai berhari-hari kemudian, karena itu
 * dikunci di sini.
 */
class TutorialEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function tutorial(array $attrs = []): StoreTutorial
    {
        return StoreTutorial::create(array_merge([
            'code'   => 'tb1',
            'slug'   => 'cara-memasang-tempat-brosur',
            'title'  => 'Cara Memasang Tempat Brosur',
            'status' => 'draft',
        ], $attrs));
    }

    public function test_setelan_trix_dimuat_sebelum_pustakanya(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('store.tutorials.edit', $this->tutorial()->id))
            ->assertOk()
            ->getContent();

        $setelan  = strpos($html, 'trix-before-initialize');
        $pustaka  = strpos($html, 'trix.umd.min.js');

        $this->assertNotFalse($setelan, 'Setelan Trix tidak ikut dirender.');
        $this->assertNotFalse($pustaka, 'Pustaka Trix tidak ikut dirender.');

        // Dibalik urutannya, tombol Heading tetap terlihat bekerja tapi hasilnya
        // hilang setiap kali halaman dibuka ulang — tanpa pesan galat apa pun.
        $this->assertLessThan($pustaka, $setelan,
            'Setelan Trix harus mendahului pustakanya, kalau tidak heading di naskah tersimpan tidak dikenali lagi.');
    }

    public function test_heading_tersimpan_apa_adanya(): void
    {
        $tutorial = $this->tutorial();

        $this->actingAs($this->admin())
            ->put(route('store.tutorials.update', $tutorial->id), [
                'action'  => 'draft',
                'code'    => 'tb1',
                'title'   => 'Cara Memasang Tempat Brosur',
                'content' => '<div>Sebelum mulai.</div><h2>Bagian tengah</h2><div>Langkah 1.</div>',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<h2>Bagian tengah</h2>', (string) $tutorial->fresh()->content);
    }

    public function test_naskah_dengan_gambar_belum_terunggah_ditolak(): void
    {
        $tersimpan = '<div>Langkah lama yang sudah benar.</div>';
        $tutorial  = $this->tutorial(['content' => $tersimpan]);

        $blob = '<div>Langkah baru.</div><figure data-trix-attachment=\'{"contentType":"image/jpeg","filename":"foto.jpg"}\' class="attachment">'
              . '<img src="blob:https://erp.noudakrilik.com/9f1c-4d2e"></figure>';

        $this->actingAs($this->admin())
            ->from(route('store.tutorials.edit', $tutorial->id))
            ->put(route('store.tutorials.update', $tutorial->id), [
                'action'  => 'draft',
                'code'    => 'tb1',
                'title'   => 'Cara Memasang Tempat Brosur',
                'content' => $blob,
            ])
            ->assertRedirect(route('store.tutorials.edit', $tutorial->id))
            ->assertSessionHasErrors('content');

        // Ditolak, bukan disimpan setengah jadi: naskah lama tetap utuh.
        $this->assertSame($tersimpan, $tutorial->fresh()->content);
    }

    public function test_gambar_yang_sudah_terunggah_tetap_tersimpan(): void
    {
        $tutorial = $this->tutorial();

        $isi = '<div>Langkah 1.</div><figure data-trix-attachment=\'{"contentType":"image/jpeg","filename":"foto.jpg","url":"https://cdn.noudakrilik.com/store/tutorials/tutorial-a1.jpg","caption":"Pasang bagian bawah"}\' class="attachment">'
             . '<img src="https://cdn.noudakrilik.com/store/tutorials/tutorial-a1.jpg">'
             . '<figcaption class="attachment__caption"><span class="attachment__name">tutorial-a1.jpg</span></figcaption></figure>';

        $this->actingAs($this->admin())
            ->put(route('store.tutorials.update', $tutorial->id), [
                'action'  => 'draft',
                'code'    => 'tb1',
                'title'   => 'Cara Memasang Tempat Brosur',
                'content' => $isi,
            ])
            ->assertRedirect();

        $simpan = (string) $tutorial->fresh()->content;

        $this->assertStringContainsString('cdn.noudakrilik.com/store/tutorials/tutorial-a1.jpg', $simpan);
        // Keterangan admin naik jadi alt + figcaption; nama berkas bawaan Trix dibuang.
        $this->assertStringContainsString('alt="Pasang bagian bawah"', $simpan);
        $this->assertStringNotContainsString('attachment__name', $simpan);
    }

    public function test_deteksi_unggahan_belum_selesai(): void
    {
        $this->assertTrue(trix_has_pending_upload('<img src="blob:https://erp.noudakrilik.com/9f1c">'));
        $this->assertFalse(trix_has_pending_upload('<img src="https://cdn.noudakrilik.com/a.jpg">'));
        $this->assertFalse(trix_has_pending_upload(null));
    }

    public function test_batas_ukuran_gambar_editor_tidak_melebihi_batas_php(): void
    {
        // Memvalidasi 12 MB sementara PHP hanya sanggup 8 MB berarti berkasnya
        // tidak pernah tiba, dan admin membaca "file wajib diisi" untuk berkas
        // yang jelas-jelas dipilihnya.
        $this->assertGreaterThan(0, editor_image_max_kb());
        $this->assertLessThanOrEqual(
            (int) config('store.editor_image_max_kb'),
            editor_image_max_kb()
        );
    }
}
