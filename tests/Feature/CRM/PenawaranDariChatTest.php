<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesQuotation;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menyusun PENAWARAN dari layar chat, memakai keranjang yang sama dengan
 * "Buat Pesanan".
 *
 * Yang dijaga di sini bukan "apakah barisnya tersimpan" melainkan tiga hal
 * yang rusaknya tidak terlihat:
 *   • arti diskon nominal berbeda antara SO (per unit) dan Penawaran (per
 *     baris) — salah terjemah berarti pelanggan menerima harga yang lain;
 *   • totalnya harus sama persis dengan SO dari keranjang yang sama, kalau
 *     tidak angka berubah sendiri saat penawaran dikonversi;
 *   • penawaran yang sudah jadi SO tidak boleh ikut tampil, kalau tidak satu
 *     kesepakatan terbaca seperti dua.
 */
class PenawaranDariChatTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');

        $this->warehouseId = Warehouse::create([
            'name' => 'Gudang Jual', 'is_sellable' => true, 'is_active' => true,
        ])->id;

        $this->productId = Product::create([
            'sku' => 'AKR-01', 'name' => 'Meja Akrilik', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ])->id;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function percakapan(): CrmConversation
    {
        return CrmConversation::findOrCreateFor('628998844666');
    }

    private function muatan(array $ganti = []): array
    {
        return array_replace_recursive([
            'warehouse_id'  => $this->warehouseId,
            'customer_name' => 'Pak Budi',
            'items' => [[
                'product_id'     => $this->productId,
                'qty'            => 2,
                'unit_price'     => 100000,
                'discount_type'  => 'nominal',
                'discount_value' => 0,
            ]],
        ], $ganti);
    }

    private function kirim(CrmConversation $p, array $muatan)
    {
        return $this->actingAs($this->admin())
            ->postJson('/erp/crm/' . $p->id . '/buat-penawaran', $muatan);
    }

    public function test_penawaran_lahir_draft_dari_keranjang_chat(): void
    {
        $this->kirim($this->percakapan(), $this->muatan())
            ->assertOk()
            ->assertJson(['success' => true]);

        $q = SalesQuotation::sole();

        $this->assertSame('draft', $q->status);
        $this->assertSame(200000.0, (float) $q->grand_total);
        $this->assertNull($q->sales_order_id, 'penawaran baru belum boleh tertaut SO');
        $this->assertSame(1, $q->items()->count());

        // Pelanggan baru ikut lahir dari nama yang diketik, seperti jalur SO.
        $this->assertNotNull($q->customer_id);
        $this->assertSame('Pak Budi', Customer::find($q->customer_id)->name);
    }

    /**
     * Diskon nominal diterjemahkan dari PER UNIT (bahasa keranjang & SO) ke
     * PER BARIS (bahasa penawaran).
     *
     * Disalin apa adanya, angkanya benar saat disimpan tapi berubah begitu
     * penawarannya dibuka lagi di halaman Sales — halaman itu menghitung
     * nominal sebagai potongan satu baris.
     */
    public function test_diskon_nominal_diterjemahkan_ke_per_baris(): void
    {
        $this->kirim($this->percakapan(), $this->muatan(['items' => [[
            'qty'            => 3,
            'unit_price'     => 10000,
            'discount_type'  => 'nominal',
            'discount_value' => 500,          // per unit
        ]]]))->assertOk();

        $item = SalesQuotation::sole()->items()->sole();

        $this->assertSame(1500.0, (float) $item->discount_value, 'nominal wajib jadi per-baris');
        $this->assertSame(28500.0, (float) $item->subtotal);

        // Accessor halaman Sales membaca angka yang sama, bukan angka lain.
        $this->assertSame(28500.0, (float) $item->line_total);
    }

    /**
     * Total penawaran = total SO dari keranjang yang sama.
     *
     * Kalau berbeda, angkanya berubah sendiri saat penawaran dikonversi — dan
     * yang menerima selisihnya adalah pelanggan yang sudah menyetujui harga.
     */
    public function test_total_penawaran_sama_dengan_total_so(): void
    {
        $muatan = $this->muatan([
            'items' => [[
                'qty'            => 3,
                'unit_price'     => 10000,
                'discount_type'  => 'nominal',
                'discount_value' => 500,
            ]],
            'global_discount_type'  => 'percent',
            'global_discount_value' => 10,
            'delivery_method'       => 'kurir',
            'shipping_gross'        => 20000,
        ]);

        $p = $this->percakapan();

        $this->kirim($p, $muatan)->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/erp/crm/' . $p->id . '/buat-so', $muatan)
            ->assertOk();

        $this->assertSame(
            (float) SalesOrder::sole()->grand_total,
            (float) SalesQuotation::sole()->grand_total
        );
    }

    /** Ambil di toko membuang ongkir, sama seperti jalur SO. */
    public function test_ambil_di_toko_membuang_ongkir(): void
    {
        $this->kirim($this->percakapan(), $this->muatan([
            'delivery_method' => 'ambil_toko',
            'shipping_gross'  => 25000,
        ]))->assertOk();

        $q = SalesQuotation::sole();

        $this->assertSame(0.0, (float) $q->shipping_charge);
        $this->assertSame(200000.0, (float) $q->grand_total);
    }

    /**
     * Penawaran yang sudah jadi SO tidak ikut tampil di panel chat.
     *
     * SO hasil konversinya sudah mewakili kesepakatan itu; menampilkan keduanya
     * membuat operator menagih dua kali, atau membuka penawaran mati untuk
     * menjawab pertanyaan tentang pesanan yang hidup.
     */
    public function test_penawaran_yang_sudah_dikonversi_tidak_ditampilkan(): void
    {
        $p = $this->percakapan();

        $this->kirim($p, $this->muatan())->assertOk();

        $hidup = SalesQuotation::sole();
        $p->forceFill(['customer_id' => $hidup->customer_id])->save();

        $mati = SalesQuotation::create([
            'quotation_number' => 'SQ/UJI/0002',
            'customer_id'      => $hidup->customer_id,
            'quotation_date'   => now()->toDateString(),
            'status'           => 'converted',
            'sales_order_id'   => 99,
            'grand_total'      => 123456,
        ]);

        $daftar = $this->actingAs($this->admin())
            ->getJson('/erp/crm/' . $p->id . '/pesanan')
            ->assertOk()
            ->json('penawaran');

        $this->assertSame([$hidup->quotation_number], array_column($daftar, 'nomor'));
        $this->assertNotContains($mati->quotation_number, array_column($daftar, 'nomor'));
    }

    /** Penawaran yang dibatalkan juga tidak menumpuk di panel. */
    public function test_penawaran_dibatalkan_tidak_ditampilkan(): void
    {
        $p = $this->percakapan();

        $this->kirim($p, $this->muatan())->assertOk();

        $q = SalesQuotation::sole();
        $p->forceFill(['customer_id' => $q->customer_id])->save();
        $q->forceFill(['status' => 'cancelled'])->save();

        $this->assertSame([], $this->actingAs($this->admin())
            ->getJson('/erp/crm/' . $p->id . '/pesanan')
            ->assertOk()
            ->json('penawaran'));
    }

    /**
     * Kartu penawaran membawa alamat PRATINJAU dan PDF-nya sendiri.
     *
     * Pratinjau menunjuk halaman CETAK, bukan berkas PDF: PDF dikirim dengan
     * Content-Disposition attachment, jadi menaruhnya di bingkai pratinjau
     * memicu unduhan alih-alih menampilkan apa pun — bingkai kosong yang tak
     * memberi petunjuk apa yang salah.
     */
    public function test_kartu_penawaran_membawa_alamat_pratinjau_dan_pdf(): void
    {
        $p = $this->percakapan();

        $this->kirim($p, $this->muatan())->assertOk();

        $q = SalesQuotation::sole();
        $p->forceFill(['customer_id' => $q->customer_id])->save();

        $kartu = $this->actingAs($this->admin())
            ->getJson('/erp/crm/' . $p->id . '/pesanan')
            ->assertOk()
            ->json('penawaran.0');

        // `pratinjau=1` wajib ikut: itu yang menyembunyikan toolbar halaman
        // cetak (tombol "Keluar" di dalamnya akan menavigasi DI DALAM bingkai)
        // dan mengunci lebarnya di A4 alih-alih mengalir mengikuti layar HP.
        $this->assertSame(
            route('sales.quotations.print', [$q->id, 'pratinjau' => 1]),
            $kartu['pratinjau']
        );
        $this->assertStringContainsString('pratinjau=1', $kartu['pratinjau']);
        $this->assertSame(route('sales.quotations.pdf', $q->id), $kartu['pdf']);
        $this->assertNotSame($kartu['pratinjau'], $kartu['pdf']);
    }

    /**
     * Halaman cetak mengenali mode pratinjau.
     *
     * Tanpa penanda di <body>, aturan layar sempit halaman itu mengubah kertas
     * jadi selebar layar — enak dibaca, tapi berhenti mewakili hasil cetak
     * justru saat dipakai memeriksa sebelum dikirim ke pelanggan.
     */
    public function test_halaman_cetak_mengenali_mode_pratinjau(): void
    {
        $this->kirim($this->percakapan(), $this->muatan())->assertOk();

        $id = SalesQuotation::sole()->id;

        $this->actingAs($this->admin())
            ->get(route('sales.quotations.print', [$id, 'pratinjau' => 1]))
            ->assertOk()
            ->assertSee('<body class="pratinjau">', false);

        // Dibuka biasa, penandanya TIDAK boleh ikut — toolbar & tata letak
        // layar sempitnya masih dipakai orang yang membuka halaman itu sendiri.
        $this->actingAs($this->admin())
            ->get(route('sales.quotations.print', $id))
            ->assertOk()
            ->assertDontSee('<body class="pratinjau">', false);
    }
}
