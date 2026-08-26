<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use App\Modules\Analysis\Support\AnalysisCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penyimpan hasil hitungan Analisa.
 *
 * Yang dijaga di sini persis dua hal yang membuat cache pantas dipercaya — dan yang
 * kalau rusak, rusaknya diam-diam:
 *
 *   1. angka tersimpan dipakai lagi selama data TIDAK berubah (itu gunanya);
 *   2. angka tersimpan DIBUANG begitu data berubah (itu syaratnya).
 *
 * Nomor 2 jauh lebih penting daripada nomor 1: halaman lambat cuma menjengkelkan,
 * sedangkan HPP basi yang menyamar jadi HPP terbaru dipakai menetapkan harga jual.
 */
class AnalysisCacheTest extends TestCase
{
    use RefreshDatabase;

    /** Permintaan baru = objek baru; sidik jarinya dihitung ulang dari keadaan tabel. */
    private function cacheBaru(): AnalysisCache
    {
        app()->forgetScopedInstances();

        return app(AnalysisCache::class);
    }

    public function test_hasil_dipakai_lagi_selama_data_tidak_berubah(): void
    {
        $this->cacheBaru()->remember('uji', [], fn () => 'hitungan-pertama');

        // Penutup kedua sengaja mengembalikan nilai lain: kalau ia sampai dijalankan,
        // berarti yang tersimpan tidak dipakai.
        $this->assertSame('hitungan-pertama',
            $this->cacheBaru()->remember('uji', [], fn () => 'DIHITUNG-ULANG'));
    }

    public function test_data_berubah_memaksa_hitung_ulang(): void
    {
        $this->cacheBaru()->remember('uji', [], fn () => 'lama');

        DB::table('products')->insert([
            'sku' => 'BARU-1', 'name' => 'Produk Baru', 'base_price' => 1000, 'sale_type' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('baru', $this->cacheBaru()->remember('uji', [], fn () => 'baru'));
    }

    public function test_baris_dihapus_juga_terhitung_sebagai_perubahan(): void
    {
        $id = DB::table('products')->insertGetId([
            'sku' => 'HAPUS-1', 'name' => 'Akan Dihapus', 'base_price' => 1000, 'sale_type' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cacheBaru()->remember('uji', [], fn () => 'lama');

        // Penghapusan tidak menggeser MAX(id) maupun MAX(updated_at) — hanya jumlah baris
        // yang menangkapnya. Tanpa COUNT di sidik jari, produk yang dihapus akan terus
        // muncul di halaman HPP sampai ada perubahan lain yang kebetulan menyusul.
        DB::table('products')->where('id', $id)->delete();

        $this->assertSame('baru', $this->cacheBaru()->remember('uji', [], fn () => 'baru'));
    }

    public function test_resep_bom_diubah_memaksa_hitung_ulang(): void
    {
        $productId = DB::table('products')->insertGetId([
            'sku' => 'BOM-UJI-1', 'name' => 'Produk Ber-BOM', 'base_price' => 1000, 'sale_type' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bomId = DB::table('boms')->insertGetId([
            'bom_number' => 'BOM-UJI-1', 'name' => 'Resep Uji',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bom_outputs')->insert([
            'bom_id' => $bomId, 'product_id' => $productId, 'qty_per_cycle' => 10,
            'output_type' => 'main', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cacheBaru()->remember('uji', [], fn () => 'sepuluh-per-siklus');

        // Hasil per siklus adalah PEMBAGI durasi timer: mengubahnya dari 10 jadi 5 membuat
        // detik-per-unit berlipat dua, dan itu mengalir ke HPP lalu ke harga jual. Jumlah
        // baris dan MAX(id) tidak bergeser sedikit pun, jadi yang menangkapnya hanya
        // MAX(updated_at) — dan itu cuma bekerja kalau tabelnya terdaftar di TABLES.
        DB::table('bom_outputs')->where('bom_id', $bomId)
            ->update(['qty_per_cycle' => 5, 'updated_at' => now()->addMinute()]);

        $this->assertSame('lima-per-siklus',
            $this->cacheBaru()->remember('uji', [], fn () => 'lima-per-siklus'));
    }

    public function test_pergantian_hari_memaksa_hitung_ulang(): void
    {
        $this->cacheBaru()->remember('uji', [], fn () => 'kemarin');

        // Sebagian perhitungan memakai jendela yang bergerak sendiri ("30 hari terakhir
        // sampai kemarin"). Lewat tengah malam jendelanya bergeser walau tidak ada satu
        // baris pun yang berubah — kalau tanggal tidak ikut disidik, angka kemarin sore
        // akan terus dipakai sepanjang hari ini.
        $this->travel(1)->days();

        $this->assertSame('hari-ini', $this->cacheBaru()->remember('uji', [], fn () => 'hari-ini'));
    }

    public function test_perintah_pemanasan_berjalan(): void
    {
        // Dijalankan cron tiap 15 menit; kalau ia melempar galat, tidak ada satu pun
        // manusia yang melihatnya sampai halamannya terasa lambat lagi.
        $this->artisan('analisa:hangatkan')->assertSuccessful();
    }

    public function test_filter_berbeda_disimpan_terpisah(): void
    {
        $this->cacheBaru()->remember('uji', ['asumsi' => false], fn () => 'harga-nyata');

        $this->assertSame('harga-asumsi',
            $this->cacheBaru()->remember('uji', ['asumsi' => true], fn () => 'harga-asumsi'));
    }

    public function test_urutan_filter_tidak_membuat_entri_kembar(): void
    {
        $this->cacheBaru()->remember('uji', ['b' => 2, 'a' => 1], fn () => 'sekali');

        // Isi filternya sama, hanya urutannya berbeda — harus jatuh ke entri yang sama,
        // kalau tidak hitungan yang sama disimpan (dan diulang) dua kali.
        $this->assertSame('sekali',
            $this->cacheBaru()->remember('uji', ['a' => 1, 'b' => 2], fn () => 'DUA-KALI'));
    }

    public function test_tombol_hitung_ulang_membuang_yang_tersimpan(): void
    {
        $this->cacheBaru()->remember('uji', [], fn () => 'lama');

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)
            ->from(route('analisa.hpp.index'))
            ->post(route('analisa.hitung-ulang'))
            ->assertRedirect(route('analisa.hpp.index'))
            ->assertSessionHas('success');

        $this->assertSame('baru', $this->cacheBaru()->remember('uji', [], fn () => 'baru'));
    }

    public function test_halaman_hpp_melaporkan_kapan_angkanya_dihitung(): void
    {
        DB::table('products')->insert([
            'sku' => 'RDY-1', 'name' => 'Produk Uji', 'base_price' => 50_000, 'sale_type' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('analisa.hpp.index'))
            ->assertOk()
            ->assertSee('Angka dihitung')
            ->assertSee('Hitung ulang');
    }

    public function test_membuka_halaman_kedua_kali_jauh_lebih_sedikit_query(): void
    {
        DB::table('products')->insert([
            'sku' => 'RDY-2', 'name' => 'Produk Uji 2', 'base_price' => 50_000, 'sale_type' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $hitung = 0;
        DB::listen(function () use (&$hitung) { $hitung++; });

        $this->actingAs($user)->get(route('analisa.hpp.index'))->assertOk();
        $pertama = $hitung;

        $hitung = 0;
        $this->actingAs($user)->get(route('analisa.hpp.index'))->assertOk();
        $kedua = $hitung;

        // Angkanya sengaja longgar: yang dijaga adalah "tidak menghitung ulang dari nol",
        // bukan jumlah query tertentu yang akan basi setiap kali halamannya disentuh.
        $this->assertLessThan($pertama, $kedua,
            "Buka kedua ($kedua query) harus lebih sedikit daripada buka pertama ($pertama query).");
    }
}
