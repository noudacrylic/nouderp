<?php

namespace Tests\Feature\Shipping;

use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ongkir & alamat mengikuti CABANG, bukan pusat.
 *
 * Kesalahan yang dijaga di sini adalah kesalahan yang TIDAK BERSUARA: ongkir
 * tetap keluar angkanya, resi tetap tercetak, alamat tetap tampil — hanya saja
 * semuanya milik kota yang keliru. Tidak ada pesan galat yang akan
 * memberitahu, dan selisihnya baru ketahuan setelah menumpuk.
 */
class OngkirCabangTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function pelanggan(): Customer
    {
        return Customer::create([
            'code'             => 'CUST-' . uniqid(),
            'name'             => 'PT Sumber Jaya',
            'phone'            => '628111111111',
            'shipping_address' => 'Jl. Pusat No. 1',
            'city'             => 'Jakarta',
            'postal_code'      => '10110',
            'jubelio_area_id'  => '1111111111',
            'is_active'        => true,
        ]);
    }

    private function cabang(Customer $customer): CustomerBranch
    {
        return $customer->branches()->create([
            'name'             => 'PT Sumber Jaya - Cabang Bandung',
            'shipping_address' => 'Jl. Merdeka No. 10',
            'city'             => 'Bandung',
            'postal_code'      => '40132',
            'jubelio_area_id'  => '2222222222',
            'is_active'        => true,
        ]);
    }

    public function test_panel_alamat_menampilkan_alamat_cabang_bukan_pusat(): void
    {
        $customer = $this->pelanggan();
        $cabang = $this->cabang($customer);

        $this->actingAs($this->admin())
            ->getJson("/erp/api/customers/{$customer->id}/shipping?branch={$cabang->id}")
            ->assertOk()
            ->assertJson([
                'branch_id'       => $cabang->id,
                'city'            => 'Bandung',
                'postal_code'     => '40132',
                'jubelio_area_id' => '2222222222',
            ]);
    }

    public function test_tanpa_cabang_panel_tetap_menampilkan_alamat_pusat(): void
    {
        $customer = $this->pelanggan();

        $this->actingAs($this->admin())
            ->getJson("/erp/api/customers/{$customer->id}/shipping")
            ->assertOk()
            ->assertJson(['branch_id' => null, 'city' => 'Jakarta', 'postal_code' => '10110']);
    }

    /**
     * Membetulkan alamat dari form SO untuk pesanan cabang tidak boleh menimpa
     * alamat kantor pusat — kalau tertimpa, pesanan berikutnya untuk pusat
     * berangkat ke kota cabang.
     */
    public function test_menyunting_alamat_saat_cabang_dipilih_tidak_menimpa_alamat_pusat(): void
    {
        $customer = $this->pelanggan();
        $cabang = $this->cabang($customer);

        $this->actingAs($this->admin())
            ->postJson("/erp/api/customers/{$customer->id}/shipping?branch={$cabang->id}", [
                'shipping_address' => 'Jl. Merdeka No. 99',
                'city'             => 'Bandung',
                'postal_code'      => '40133',
            ])
            ->assertOk();

        $this->assertSame('Jl. Merdeka No. 99', $cabang->fresh()->shipping_address);
        $this->assertSame('Jl. Pusat No. 1', $customer->fresh()->shipping_address);
    }

    public function test_tanpa_cabang_penyuntingan_alamat_mengenai_pusat(): void
    {
        $customer = $this->pelanggan();

        $this->actingAs($this->admin())
            ->postJson("/erp/api/customers/{$customer->id}/shipping", [
                'shipping_address' => 'Jl. Pusat No. 2',
                'city'             => 'Jakarta',
            ])
            ->assertOk();

        $this->assertSame('Jl. Pusat No. 2', $customer->fresh()->shipping_address);
    }

    /** Cabang perusahaan lain tidak boleh jadi tujuan ongkir. */
    public function test_cabang_milik_pelanggan_lain_jatuh_ke_alamat_induk(): void
    {
        $pemilik   = $this->pelanggan();
        $cabang    = $this->cabang($pemilik);
        $orangLain = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'PT Tetangga',
            'city' => 'Surabaya', 'postal_code' => '60111', 'is_active' => true,
        ]);

        $tujuan = CustomerBranch::tujuanUntuk($orangLain->id, $cabang->id);

        $this->assertSame('Surabaya', $tujuan->city);
        $this->assertNotSame('40132', $tujuan->postal_code);
    }

    /** Area ongkir cabang inilah yang dipakai melengkapi permintaan tarif. */
    public function test_area_ongkir_diambil_dari_cabang(): void
    {
        $customer = $this->pelanggan();
        $cabang = $this->cabang($customer);

        $tujuan = CustomerBranch::tujuanUntuk($customer->id, $cabang->id);

        $this->assertSame('2222222222', $tujuan->jubelio_area_id);
        $this->assertSame('40132', $tujuan->postal_code);
    }
}
