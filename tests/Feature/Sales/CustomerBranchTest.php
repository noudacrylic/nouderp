<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cabang pelanggan — tahap 1: pengelolaannya dari menu Customer.
 *
 * Yang dijaga di sini bukan "formnya bisa dipakai", melainkan dua hal yang
 * kalau jebol merusak data sungguhan: cabang tidak boleh berpindah pelanggan
 * lewat tebak-tebakan angka di URL, dan alamat pusat yang dipinjam cabang
 * beralamat kosong tidak boleh tersalin ke baris cabangnya.
 */
class CustomerBranchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function pelanggan(string $nama = 'PT Sumber Jaya'): Customer
    {
        return Customer::create([
            'code'      => 'CUST-' . uniqid(),
            'name'      => $nama,
            'phone'     => '08111111111',
            'is_active' => true,
        ]);
    }

    private function isian(array $ganti = []): array
    {
        return array_merge([
            'name'             => 'PT Sumber Jaya - Cabang Bandung',
            'pic_name'         => 'Budi',
            'recipient_phone'  => '08222222222',
            'shipping_address' => 'Jl. Merdeka No. 10',
            'district'         => 'Coblong',
            'city'             => 'Bandung',
            'province'         => 'Jawa Barat',
            'postal_code'      => '40132',
            'jubelio_area_id'  => '1234567890',
            'location_point'   => '-6.8915,107.6107',
        ], $ganti);
    }

    public function test_cabang_tersimpan_di_bawah_pelanggannya_beserta_area_ongkir(): void
    {
        $customer = $this->pelanggan();

        $this->actingAs($this->admin())
            ->post(route('customers.branches.store', $customer->id), $this->isian())
            ->assertRedirect(route('customers.edit', $customer->id));

        $cabang = CustomerBranch::firstOrFail();

        $this->assertSame($customer->id, $cabang->customer_id);
        $this->assertSame('Bandung', $cabang->city);

        // Area & koordinat WAJIB ikut cabang. Kalau tertinggal di induk, ongkir
        // tiap cabang dihitung ke kota pusat tanpa gejala apa pun.
        $this->assertSame('1234567890', $cabang->jubelio_area_id);
        $this->assertEqualsWithDelta(-6.8915, (float) $cabang->latitude, 0.0001);
        $this->assertEqualsWithDelta(107.6107, (float) $cabang->longitude, 0.0001);
    }

    public function test_cabang_tidak_bisa_dipindah_ke_pelanggan_lain_lewat_url(): void
    {
        $pemilik = $this->pelanggan('PT Sumber Jaya');
        $orangLain = $this->pelanggan('PT Tetangga');

        $cabang = $pemilik->branches()->create(['name' => 'Cabang Bandung']);

        $this->actingAs($this->admin())
            ->put(route('customers.branches.update', [$orangLain->id, $cabang->id]), $this->isian())
            ->assertNotFound();

        $this->assertSame($pemilik->id, $cabang->fresh()->customer_id);
    }

    /**
     * Cabang beralamat kosong MENGIRIM ke alamat pusat (lihat TujuanCabangDokumenTest),
     * tapi alamat pusat itu tidak pernah ditulis ke baris cabangnya — begitu alamat
     * pusat diubah, cabang ikut berubah, dan begitu cabang diberi alamat, ia berdiri sendiri.
     */
    public function test_alamat_cabang_yang_kosong_tidak_tersalin_ke_baris_cabang(): void
    {
        $customer = $this->pelanggan();
        $customer->update(['address' => 'Jl. Pusat No. 1, Jakarta']);

        $cabang = $customer->branches()->create(['name' => 'Cabang Baru']);

        $this->assertSame('', $cabang->fullAddress());
        $this->assertFalse($cabang->punyaAlamatSendiri());
    }

    public function test_arsip_menyembunyikan_cabang_dari_yang_aktif_tanpa_menghapusnya(): void
    {
        $customer = $this->pelanggan();
        $cabang = $customer->branches()->create(['name' => 'Cabang Lama', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('customers.branches.archive', [$customer->id, $cabang->id]));

        $this->assertFalse($cabang->fresh()->is_active);
        $this->assertSame(0, CustomerBranch::aktif()->count());
        $this->assertSame(1, CustomerBranch::count());
    }

    public function test_nama_cabang_wajib_diisi(): void
    {
        $customer = $this->pelanggan();

        $this->actingAs($this->admin())
            ->post(route('customers.branches.store', $customer->id), $this->isian(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, CustomerBranch::count());
    }
}
