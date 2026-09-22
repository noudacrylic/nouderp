<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tujuan dokumen penjualan: cabang bila dipilih, kalau tidak induk.
 *
 * Yang dijaga adalah SATU BENTUK. `tujuan()` selalu mengembalikan CustomerBranch
 * — baris cabang sungguhan atau induk yang dituangkan jadi cabang bayangan —
 * supaya lima belas pembaca alamat di seluruh ERP tidak perlu masing-masing
 * menulis ulang aturan "pakai cabang kalau ada". Aturan yang disalin akan
 * melenceng satu per satu, dan melencengnya tidak bersuara: ongkir tetap
 * keluar, resi tetap tercetak, cuma alamatnya milik kota yang salah.
 */
class TujuanCabangDokumenTest extends TestCase
{
    use RefreshDatabase;

    private function pelanggan(): Customer
    {
        return Customer::create([
            'code'             => 'CUST-' . uniqid(),
            'name'             => 'PT Sumber Jaya',
            'phone'            => '628111111111',
            'shipping_address' => 'Jl. Pusat No. 1',
            'city'             => 'Jakarta',
            'province'         => 'DKI Jakarta',
            'postal_code'      => '10110',
            'is_active'        => true,
        ]);
    }

    private function cabang(Customer $customer): \App\Models\CustomerBranch
    {
        return $customer->branches()->create([
            'name'             => 'PT Sumber Jaya - Cabang Bandung',
            'phone'            => '628333333333',
            'shipping_address' => 'Jl. Merdeka No. 10',
            'district'         => 'Coblong',
            'city'             => 'Bandung',
            'province'         => 'Jawa Barat',
            'postal_code'      => '40132',
            'is_active'        => true,
        ]);
    }

    private function pesanan(Customer $customer, $cabangId = null): SalesOrder
    {
        return SalesOrder::create([
            'order_number'       => 'SO-TC-' . uniqid(),
            'customer_id'        => $customer->id,
            'customer_branch_id' => $cabangId,
            'warehouse_id'       => Warehouse::firstOrCreate(['name' => 'Gudang Uji Tujuan'])->id,
            'order_date'         => now(),
            'status'             => 'draft',
            'grand_total'        => 500000,
        ]);
    }

    public function test_tanpa_cabang_dokumen_memakai_data_induk(): void
    {
        $so = $this->pesanan($this->pelanggan());

        $this->assertSame('PT Sumber Jaya', $so->namaTujuan());
        $this->assertSame('Jl. Pusat No. 1, Jakarta, DKI Jakarta, 10110', $so->alamatTujuan());
    }

    public function test_dengan_cabang_nota_mencetak_nama_dan_alamat_cabang(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        $this->assertSame('PT Sumber Jaya - Cabang Bandung', $so->namaTujuan());
        $this->assertSame('Jl. Merdeka No. 10, Coblong, Bandung, Jawa Barat, 40132', $so->alamatTujuan());
    }

    /**
     * Piutang TIDAK boleh ikut pindah ke cabang — itu seluruh alasan cabang
     * bukan dibuat sebagai pelanggan tersendiri.
     */
    public function test_dokumen_tetap_menempel_ke_pelanggan_induk(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        $this->assertSame($customer->id, $so->customer_id);
    }

    public function test_surat_jalan_mewarisi_cabang_pesanannya(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        // Sengaja TANPA customer_branch_id sendiri: surat jalan lama & jalur
        // yang terlewat disambungkan harus tetap mencetak alamat yang benar.
        $sj = SalesDelivery::create([
            'delivery_number' => 'DO-TC-' . uniqid(),
            'sales_order_id'  => $so->id,
            'reference_type'  => 'sales_order',
            'reference_id'    => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_date'   => now(),
            'status'          => 'draft',
        ]);

        $this->assertSame('PT Sumber Jaya - Cabang Bandung', $sj->fresh()->namaTujuan());
    }

    public function test_faktur_mewarisi_cabang_pesanannya(): void
    {
        $customer = $this->pelanggan();
        $so = $this->pesanan($customer, $this->cabang($customer)->id);

        $faktur = SalesInvoice::create([
            'invoice_number' => 'INV-TC-' . uniqid(),
            'sales_order_id' => $so->id,
            'customer_id'    => $customer->id,
            'warehouse_id'   => $so->warehouse_id,
            'invoice_date'   => now(),
            'status'         => 'draft',
            'grand_total'    => 500000,
        ]);

        $this->assertSame('PT Sumber Jaya - Cabang Bandung', $faktur->fresh()->namaTujuan());
    }

    /**
     * Cabang datang dari kolom tersembunyi, dan kolom tersembunyi bisa disetel
     * siapa saja. Tanpa pagar ini, satu angka yang diganti sudah cukup membuat
     * pesanan tercetak dengan alamat perusahaan lain.
     */
    public function test_cabang_milik_pelanggan_lain_ditolak(): void
    {
        $pemilik   = $this->pelanggan();
        $cabang    = $this->cabang($pemilik);
        $orangLain = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'PT Tetangga', 'is_active' => true,
        ]);

        $penerima = new class {
            use \App\Http\Controllers\Concerns\MenyimpanCabangPelanggan;

            public function uji($request, $customerId)
            {
                return $this->cabangDariRequest($request, $customerId);
            }
        };

        $request = new \Illuminate\Http\Request(['customer_branch_id' => $cabang->id]);

        $this->assertSame($cabang->id, $penerima->uji($request, $pemilik->id));
        $this->assertNull($penerima->uji($request, $orangLain->id));
    }

    /** Alamat induk jatuh ke `address` bila alamat kirimnya belum diisi. */
    public function test_induk_tanpa_alamat_kirim_memakai_alamat_penagihan(): void
    {
        $customer = $this->pelanggan();
        $customer->update(['shipping_address' => null, 'address' => 'Jl. Penagihan No. 7']);

        $so = $this->pesanan($customer->fresh());

        $this->assertStringContainsString('Jl. Penagihan No. 7', $so->alamatTujuan());
    }

    /**
     * Cabang yang alamatnya belum diisi mengirim ke alamat PUSAT (keputusan 22 Sep 2026).
     * Nama & nomor tetap milik cabang — nota atas nama cabang, kurir menelepon orang cabang.
     * Dulu resinya ditolak "alamat jalan kosong" padahal di layar tertulis "ikut pusat".
     */
    public function test_cabang_tanpa_alamat_memakai_alamat_pusat_tetapi_nama_cabang(): void
    {
        $customer = $this->pelanggan();
        $customer->update(['jubelio_area_id' => '3374010001', 'latitude' => -6.2, 'longitude' => 106.8]);
        // Titik lokasi saja belum dihitung alamat: kurir reguler butuh jalan & kode pos.
        $cabang = $customer->branches()->create([
            'name' => 'PT Sumber Jaya - Cabang Unnes', 'recipient_phone' => '628444444444',
            'latitude' => -7.04, 'longitude' => 110.39, 'is_active' => true,
        ]);

        $tujuan = $this->pesanan($customer->fresh(), $cabang->id)->tujuan();

        $this->assertSame('PT Sumber Jaya - Cabang Unnes', $tujuan->name);
        $this->assertSame('Jl. Pusat No. 1', $tujuan->shipping_address);
        $this->assertSame('10110', $tujuan->postal_code);
        $this->assertSame('3374010001', $tujuan->jubelio_area_id);
        // Satu kesatuan: koordinat ikut pusat, bukan koordinat cabang + jalan pusat.
        $this->assertEqualsWithDelta(-6.2, (float) $tujuan->latitude, 0.0001);
        $this->assertSame('628444444444', $tujuan->nomorPengiriman());

        // Baris cabangnya sendiri tidak ikut terisi alamat pusat.
        $this->assertNull($cabang->fresh()->shipping_address);
    }

    /** Alamat cabang yang terisi sebagian TIDAK ditambal dari pusat — tidak ada alamat campuran. */
    public function test_cabang_beralamat_sebagian_tidak_dicampur_alamat_pusat(): void
    {
        $customer = $this->pelanggan();
        $cabang = $customer->branches()->create([
            'name' => 'Cabang Setengah', 'city' => 'Bandung', 'is_active' => true,
        ]);

        $tujuan = $this->pesanan($customer, $cabang->id)->tujuan();

        $this->assertSame('Bandung', $tujuan->city);
        $this->assertNull($tujuan->shipping_address);
        $this->assertNull($tujuan->postal_code);
    }

    public function test_cabang_dengan_alamat_pinjaman_pusat_tidak_bisa_disimpan(): void
    {
        $customer = $this->pelanggan();
        $cabang = $customer->branches()->create(['name' => 'Cabang Kosong', 'is_active' => true]);

        $this->expectException(\LogicException::class);
        $this->pesanan($customer, $cabang->id)->tujuan()->save();
    }

    public function test_panel_alamat_cabang_kosong_menampilkan_alamat_pusat(): void
    {
        $customer = $this->pelanggan();
        $cabang = $customer->branches()->create(['name' => 'Cabang Kosong', 'is_active' => true]);

        $tujuan = \App\Models\CustomerBranch::tujuanUntuk($customer->id, $cabang->id);

        $this->assertSame($cabang->id, $tujuan->id);
        $this->assertSame('Jl. Pusat No. 1, Jakarta, DKI Jakarta, 10110', $tujuan->fullAddress());
    }
}
