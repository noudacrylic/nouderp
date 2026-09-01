<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembeli sering berubah pikiran SETELAH SO di-post dan DP masuk: semula mau ambil
 * di toko, ternyata minta dikirim (atau sebaliknya). Sebelumnya satu-satunya jalan
 * adalah void SO — padahal void tertahan oleh DP yang sudah diposting, jadi buntu.
 *
 * Kartu Pengiriman di halaman SO kini boleh mengganti METODE juga, bukan hanya kurir
 * & ongkir. Yang berubah cuma jalur kirim: item, reservasi stok, dan DP tidak tersentuh.
 */
class SalesOrderUbahPengirimanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function so(array $attrs = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Pembeli Berubah Pikiran', 'is_active' => true,
        ]);

        return SalesOrder::create(array_merge([
            'order_number'         => 'SO-SHIP-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true])->id,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'delivery_method'      => 'ambil_toko',
            'pickup_code'          => '7340',
            'pickup_status'        => 'pending',
            'subtotal'             => 1_120_000,
            'grand_total'          => 1_120_000,
            'shipping_cost'        => 0,
            'paid_amount'          => 560_000,
        ], $attrs));
    }

    private function kurirSo(array $attrs = []): SalesOrder
    {
        return $this->so(array_merge([
            'delivery_method'       => 'kurir',
            'pickup_code'           => null,
            'pickup_status'         => null,
            'shipping_cost'         => 20_000,
            'shipping_gross'        => 20_000,
            'shipping_courier_code' => 'jne',
            'grand_total'           => 1_140_000,
        ], $attrs));
    }

    private function kirimUbah(SalesOrder $so, array $payload)
    {
        return $this->actingAs($this->admin())
            ->post(route('sales.orders.update-shipping', $so->id), $payload);
    }

    // ───────────── Ambil di Toko → Kurir ─────────────

    public function test_ambil_toko_bisa_diubah_jadi_kurir_meski_so_sudah_dipost(): void
    {
        $so = $this->so();

        $this->kirimUbah($so, [
            'delivery_method'         => 'kurir',
            'shipping_gross'          => '25.000',
            'shipping_discount_type'  => 'nominal',
            'shipping_discount_value' => '0',
            'shipping_courier_code'   => 'jne',
            'shipping_service_code'   => 'REG',
            'shipping_service_name'   => 'JNE Reguler',
        ])->assertSessionHas('success');

        $so->refresh();
        $this->assertSame('kurir', $so->delivery_method);
        $this->assertSame(25_000.0, (float) $so->shipping_cost);
        $this->assertSame('jne', $so->shipping_courier_code);
        // Ongkir masuk ke grand total; DP yang sudah dibayar tidak diutak-atik.
        $this->assertSame(1_145_000.0, (float) $so->grand_total);
        $this->assertSame(560_000.0, (float) $so->paid_amount);
        // Booking code & jadwal ambil tidak berlaku lagi.
        $this->assertNull($so->pickup_code);
        $this->assertNull($so->pickup_status);
        $this->assertNull($so->pickup_date);
    }

    // ───────────── Kurir → Ambil di Toko ─────────────

    public function test_kurir_bisa_diubah_jadi_ambil_toko_dan_booking_code_terbit_otomatis(): void
    {
        $so = $this->kurirSo();

        $this->kirimUbah($so, [
            'delivery_method' => 'ambil_toko',
            'pickup_date'     => now()->addDays(2)->toDateString(),
            // Sisa isian kurir dari form sengaja ikut terkirim — harus diabaikan.
            'shipping_gross'        => '20.000',
            'shipping_courier_code' => 'jne',
        ])->assertSessionHas('success');

        $so->refresh();
        $this->assertSame('ambil_toko', $so->delivery_method);
        $this->assertSame(0.0, (float) $so->shipping_cost);
        $this->assertNull($so->shipping_courier_code);
        // Ongkir lama dikeluarkan dari grand total.
        $this->assertSame(1_120_000.0, (float) $so->grand_total);
        // Booking code terbit sendiri — tidak perlu lagi lewat Edit SO.
        $this->assertNotEmpty($so->pickup_code);
        $this->assertSame('pending', $so->pickup_status);
        $this->assertSame(now()->addDays(2)->toDateString(), $so->pickup_date->toDateString());
    }

    public function test_booking_code_lama_tidak_diterbitkan_ulang(): void
    {
        // Pernah ambil-toko → pindah kurir → balik lagi ke ambil toko.
        $so = $this->so(['pickup_code' => '1234']);

        $this->kirimUbah($so, ['delivery_method' => 'kurir', 'shipping_gross' => '10.000']);
        $this->assertNull($so->refresh()->pickup_code);

        $this->kirimUbah($so, ['delivery_method' => 'ambil_toko']);
        $so->refresh();
        $this->assertNotEmpty($so->pickup_code, 'Kode baru harus terbit setelah kembali ke ambil toko.');
    }

    public function test_so_draft_belum_dapat_booking_code_kodenya_terbit_saat_post(): void
    {
        $so = $this->kurirSo(['status' => 'draft']);

        $this->kirimUbah($so, ['delivery_method' => 'ambil_toko']);

        $so->refresh();
        $this->assertSame('ambil_toko', $so->delivery_method);
        $this->assertNull($so->pickup_code, 'SO draft dapat kodenya saat di-post, bukan di sini.');
    }

    // ───────────── Perilaku lama tetap jalan ─────────────

    public function test_ganti_kurir_saja_tetap_seperti_semula(): void
    {
        $so = $this->kurirSo();

        $this->kirimUbah($so, [
            'delivery_method'       => 'kurir',
            'shipping_gross'        => '35.000',
            'shipping_courier_code' => 'sicepat',
            'shipping_service_name' => 'SiCepat BEST',
        ])->assertSessionHas('success');

        $so->refresh();
        $this->assertSame('sicepat', $so->shipping_courier_code);
        $this->assertSame(35_000.0, (float) $so->shipping_cost);
        $this->assertSame(1_155_000.0, (float) $so->grand_total);
    }

    public function test_faktur_draft_ikut_disinkronkan_saat_metode_berubah(): void
    {
        $so = $this->so();
        $inv = SalesInvoice::create([
            'invoice_number'  => 'INV-SHIP-' . uniqid(),
            'sales_order_id'  => $so->id,
            'customer_id'     => $so->customer_id,
            'warehouse_id'    => $so->warehouse_id,
            'invoice_date'    => now()->toDateString(),
            'status'          => 'draft',
            'delivery_method' => 'ambil_toko',
            'shipping_cost'   => 0,
            'grand_total'     => 1_120_000,
        ]);

        $this->kirimUbah($so, [
            'delivery_method'       => 'kurir',
            'shipping_gross'        => '25.000',
            'shipping_courier_code' => 'jne',
        ]);

        $inv->refresh();
        $this->assertSame('kurir', $inv->delivery_method);
        $this->assertSame(25_000.0, (float) $inv->shipping_cost);
        $this->assertSame(1_145_000.0, (float) $inv->grand_total);
    }

    // ───────────── Gerbang pengaman ─────────────

    public function test_faktur_yang_sudah_diposting_mengunci_pengiriman(): void
    {
        $so = $this->so();
        SalesInvoice::create([
            'invoice_number' => 'INV-POSTED-' . uniqid(),
            'sales_order_id' => $so->id,
            'customer_id'    => $so->customer_id,
            'warehouse_id'   => $so->warehouse_id,
            'invoice_date'   => now()->toDateString(),
            'status'         => 'posted',
            'grand_total'    => 1_120_000,
        ]);

        $this->kirimUbah($so, ['delivery_method' => 'kurir', 'shipping_gross' => '25.000'])
            ->assertSessionHas('error');

        $this->assertSame('ambil_toko', $so->refresh()->delivery_method);
    }

    public function test_pesanan_yang_sudah_diambil_tidak_bisa_diubah(): void
    {
        $so = $this->so(['pickup_status' => 'picked_up', 'picked_up_at' => now()]);

        $this->kirimUbah($so, ['delivery_method' => 'kurir', 'shipping_gross' => '25.000'])
            ->assertSessionHas('error');

        $this->assertSame('ambil_toko', $so->refresh()->delivery_method);
    }

    public function test_surat_jalan_aktif_menghadang_pergantian_metode(): void
    {
        $so = $this->kurirSo();
        SalesDelivery::create([
            'delivery_number' => 'SJ-' . uniqid(),
            'sales_order_id'  => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_date'   => now()->toDateString(),
            'status'          => 'posted',
        ]);

        $this->kirimUbah($so, ['delivery_method' => 'ambil_toko'])
            ->assertSessionHas('error');

        $this->assertSame('kurir', $so->refresh()->delivery_method);
    }

    public function test_so_void_tetap_terkunci(): void
    {
        $so = $this->so(['status' => 'void']);

        $this->kirimUbah($so, ['delivery_method' => 'kurir', 'shipping_gross' => '25.000'])
            ->assertSessionHas('error');

        $this->assertSame('ambil_toko', $so->refresh()->delivery_method);
    }

    public function test_metode_ngawur_diabaikan_bukan_menimpa(): void
    {
        $so = $this->so();

        $this->kirimUbah($so, ['delivery_method' => 'teleportasi']);

        $this->assertSame('ambil_toko', $so->refresh()->delivery_method);
    }

    // ───────────── Tampilan ─────────────

    public function test_kartu_pengiriman_muncul_juga_di_so_ambil_toko(): void
    {
        $so = $this->so();

        $this->actingAs($this->admin())
            ->get(route('sales.orders.show', $so->id))
            ->assertOk()
            ->assertSee('Ubah Pengiriman')
            ->assertSee('Ambil di Toko');
    }

    public function test_kartu_pengiriman_disembunyikan_setelah_barang_diambil(): void
    {
        $so = $this->so(['pickup_status' => 'picked_up', 'picked_up_at' => now()]);

        $this->actingAs($this->admin())
            ->get(route('sales.orders.show', $so->id))
            ->assertOk()
            ->assertDontSee('Ubah Pengiriman');
    }
}
