<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lingkaran "login → Page Expired".
 *
 * Saat sesi habis, form apa pun yang dikirim gagal uji CSRF. Laravel 11+
 * mengubah TokenMismatchException jadi HttpException(419) SEBELUM render
 * callback dijalankan, jadi penangan lama yang di-type-hint
 * TokenMismatchException tidak pernah jalan dan orang melihat halaman buntu
 * "419 | PAGE EXPIRED". Selain itu `url.intended` yang basi bisa menyeret user
 * kembali ke halaman login atau ke endpoint yang bukan halaman, sehingga login
 * yang sebenarnya berhasil terasa gagal.
 */
class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'username'  => 'budi',
            'password'  => Hash::make('rahasia'),
            'role'      => 'super_admin',
            'is_active' => true,
        ], $attrs));
    }

    /** CSRF di-skip otomatis saat env 'testing'; paksa aktif supaya bisa diuji. */
    private function enableCsrf(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_sesi_mati_saat_submit_tidak_menampilkan_halaman_419(): void
    {
        $this->enableCsrf();

        $response = $this->from(url('/erp/sales/orders/create'))
            ->post('/erp/sales/orders', ['_token' => 'token-basi']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('session');
    }

    public function test_halaman_asal_dikembalikan_setelah_login_ulang(): void
    {
        $this->enableCsrf();
        $this->user();

        $this->from(url('/erp/sales/orders/create'))
            ->post('/erp/sales/orders', ['_token' => 'token-basi']);

        $this->assertSame(url('/erp/sales/orders/create'), session('url.intended'));

        $this->app['env'] = 'testing';
        $this->post('/login', ['username' => 'budi', 'password' => 'rahasia'])
            ->assertRedirect(url('/erp/sales/orders/create'));
    }

    public function test_intended_ke_halaman_login_sendiri_diabaikan(): void
    {
        $this->user();
        session(['url.intended' => url('/login')]);

        $this->post('/login', ['username' => 'budi', 'password' => 'rahasia'])
            ->assertRedirect(url('/erp/dashboard'));
    }

    public function test_intended_ke_host_lain_diabaikan(): void
    {
        $this->user();
        session(['url.intended' => 'https://situs-lain.test/erp/dashboard']);

        $this->post('/login', ['username' => 'budi', 'password' => 'rahasia'])
            ->assertRedirect(url('/erp/dashboard'));
    }

    public function test_akun_karyawan_tidak_dilempar_ke_halaman_erp(): void
    {
        $this->user(['role' => 'karyawan']);
        session(['url.intended' => url('/erp/sales/orders')]);

        $this->post('/login', ['username' => 'budi', 'password' => 'rahasia'])
            ->assertRedirect(url('/me'));
    }
}
