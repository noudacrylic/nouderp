<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Landing module saat menu utama di sidebar diklik.
 *
 * Yang dijaga: URL "submenu terakhir" yang tersimpan di session bisa jadi BASI
 * ketika susunan menu berubah — sebuah halaman dipindah dari satu module ke
 * module lain, sementara session pengguna masih memegang alamat lamanya.
 * Gejalanya membingungkan: klik menu CRM justru membuka halaman module lain,
 * dan orang menyimpulkan "menunya rusak" padahal datanya yang kedaluwarsa.
 * Session tidak bisa dibersihkan dari luar, jadi ia divalidasi saat dipakai.
 */
class MenuLandingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_url_tersimpan_milik_module_lain_diabaikan(): void
    {
        $this->actingAs($this->admin());

        // Peninggalan saat "Pengaturan CRM" masih anak menu CRM.
        session(['last_submenu.crm' => url('/erp/settings/crm')]);

        $this->assertFalse(submenu_url_belongs_to(url('/erp/settings/crm'), 'crm'));
        $this->assertSame('/erp/crm', module_landing_url('crm'));
    }

    public function test_url_tersimpan_yang_masih_sah_tetap_dipakai(): void
    {
        $this->actingAs($this->admin());

        session(['last_submenu.crm' => url('/erp/crm/notifikasi')]);

        $this->assertSame(url('/erp/crm/notifikasi'), module_landing_url('crm'));
    }

    public function test_url_yang_rutenya_sudah_tidak_ada_diabaikan(): void
    {
        $this->actingAs($this->admin());

        session(['last_submenu.crm' => url('/erp/crm/halaman-yang-sudah-dihapus/xyz')]);

        $this->assertSame('/erp/crm', module_landing_url('crm'));
    }
}
