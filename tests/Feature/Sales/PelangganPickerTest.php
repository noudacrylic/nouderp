<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kotak "Pelanggan" di dokumen penjualan.
 *
 * Tiga hal yang dijaga di sini: pelanggan arsip tidak boleh muncul lagi sebagai
 * pilihan, nama kembar dihadang sebelum tersimpan, dan yang tampil membawa kode
 * pelanggannya supaya "ambil yang sudah ada" terlihat beda dari "ketik nama baru".
 */
class PelangganPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    private function pelanggan(string $nama, string $kode, bool $aktif = true): Customer
    {
        return Customer::create([
            'code' => $kode,
            'name' => $nama,
            'is_active' => $aktif,
        ]);
    }

    public function test_pelanggan_arsip_tidak_muncul_di_pencarian(): void
    {
        $this->pelanggan('Dhita Maharani', 'CUST-AKTIF');
        $this->pelanggan('Dhita Lama', 'CUST-ARSIP', false);

        $hasil = $this->getJson('/erp/api/customers/search?q=Dhita')->assertOk()->json();

        $this->assertCount(1, $hasil);
        $this->assertSame('Dhita Maharani', $hasil[0]['name']);
    }

    public function test_arsip_juga_tidak_bocor_lewat_pencarian_kode(): void
    {
        // Pencarian menyapu nama ATAU kode. Tanpa pengelompokan yang benar, saringan
        // aktifnya cuma menempel pada cabang nama dan arsipnya lolos lewat kode.
        $this->pelanggan('Siapa Saja', 'CUST-ARSIP-9', false);

        $hasil = $this->getJson('/erp/api/customers/search?q=CUST-ARSIP-9')->assertOk()->json();

        $this->assertSame([], $hasil);
    }

    public function test_hasil_pencarian_membawa_kode_pelanggan(): void
    {
        $this->pelanggan('Ifa', 'CUST-777');

        $hasil = $this->getJson('/erp/api/customers/search?q=Ifa')->assertOk()->json();

        $this->assertSame('CUST-777 · Ifa', $hasil[0]['label']);
    }

    public function test_nama_kembar_dihadang_dan_menawarkan_yang_sudah_ada(): void
    {
        $lama = $this->pelanggan('Dhita Maharani', 'CUST-001');

        $res = $this->postJson('/customers/store', ['name' => 'dhita  maharani'])
            ->assertStatus(409)
            ->assertJson(['duplicate' => true]);

        $this->assertSame($lama->id, $res->json('existing.0.id'));
        $this->assertSame('CUST-001 · Dhita Maharani', $res->json('existing.0.label'));
        $this->assertSame(1, Customer::where('name', 'like', 'dhita%')->count());
    }

    public function test_kembar_boleh_disimpan_bila_ditegaskan(): void
    {
        $this->pelanggan('Dhita Maharani', 'CUST-001');

        $this->postJson('/customers/store', ['name' => 'Dhita Maharani', 'force' => 1])
            ->assertOk();

        $this->assertSame(2, Customer::whereRaw('LOWER(name) = ?', ['dhita maharani'])->count());
    }

    public function test_nama_yang_kembar_dengan_arsip_tidak_dihadang(): void
    {
        // Yang diarsipkan sudah dianggap tidak ada; menghadangnya justru membuat nama
        // itu terkunci selamanya tanpa cara membebaskannya dari layar ini.
        $this->pelanggan('Sudah Pensiun', 'CUST-ARSIP-2', false);

        $this->postJson('/customers/store', ['name' => 'Sudah Pensiun'])->assertOk();

        $this->assertSame(2, Customer::where('name', 'Sudah Pensiun')->count());
    }

    public function test_kode_pelanggan_tidak_ikut_tercetak_di_nota(): void
    {
        // Kode hanya alat bantu di layar. Yang tercetak untuk pembeli harus namanya saja,
        // jadi label berkode itu memang tidak boleh punya jalan menuju dokumen tersimpan.
        $cust = $this->pelanggan('Dhita Maharani', 'CUST-001');

        $so = \App\Modules\Sales\Models\SalesOrder::create([
            'order_number' => 'SO-CETAK-1',
            'customer_id' => $cust->id,
            'warehouse_id' => \App\Core\Inventory\Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => 'confirmed',
            'grand_total' => 100000,
        ]);

        $this->get('/erp/sales/orders/' . $so->id . '/print')
            ->assertOk()
            ->assertSee('Dhita Maharani')
            ->assertDontSee('CUST-001');
    }

    public function test_pelanggan_baru_mengembalikan_label_berkode(): void
    {
        $res = $this->postJson('/customers/store', ['name' => 'Pelanggan Baru'])->assertOk();

        $this->assertStringContainsString('· Pelanggan Baru', $res->json('label'));
        $this->assertStringStartsWith('CUST-', $res->json('code'));
    }
}
