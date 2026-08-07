<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Belum Siap" dulu menampung tiga keadaan berbeda sekaligus: belum dibayar, barangnya masih
 * diproduksi, dan sudah DP tapi belum lunas. Ketiganya menuntut tindakan yang berbeda —
 * menagih, menunggu produksi, menagih pelunasan — jadi sekarang dipisah jadi bucket sendiri.
 *
 * Aturan penting: pesanan yang baru DP TIDAK lagi langsung masuk antrean kerja; ia tertahan
 * di "Belum Lunas" sampai lunas, kecuali admin melepasnya dengan mencatat alasan.
 */
class FulfillmentBucketSplitTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function so(array $attrs = []): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);

        return SalesOrder::create(array_merge([
            'order_number'         => 'SO-FF-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'grand_total'          => 100000,
            'paid_amount'          => 0,
            // Berkas ini menguji gerbang PEMBAYARAN & urutan prioritas, bukan pengukuran.
            // Tanpa penanda ini setiap pesanan lunas akan berhenti di "Perlu Ukur" dan yang
            // diuji jadi kabur. Gerbang ukurnya sendiri diuji di FulfillmentPerluUkurTest.
            'measured_at'          => now(),
        ], $attrs));
    }

    /** SO dengan satu item produk custom (preorder) — memaksa jalur "tunggu produksi". */
    private function soCustom(array $attrs = []): SalesOrder
    {
        $so = $this->so($attrs);

        $product = Product::create([
            'sku' => 'CUSTOM-' . uniqid(), 'name' => 'Akrilik Custom',
            'sale_type' => 'preorder', 'lead_time_days' => 3,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'qty'                => 1,
            'conversion_to_base' => 1,
            'unit_price'         => 100000,
            'net_unit_price'     => 100000,
            'line_subtotal'      => 100000,
            'line_discount'      => 0,
            'line_total'         => 100000,
        ]);

        return $so->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /**
     * Bucket tempat sebuah SO berada. Service-nya singleton & memoized per-request (sengaja,
     * supaya badge tab tidak menghitung ulang), jadi di tes yang mengubah data di tengah jalan
     * instance-nya dilupakan dulu — kalau tidak, yang terbaca adalah klasifikasi lama.
     */
    private function bucketOf(SalesOrder $so): ?string
    {
        app()->forgetInstance(FulfillmentReadinessService::class);
        $svc = app(FulfillmentReadinessService::class);

        foreach (['belum_bayar', 'belum_siap', 'belum_lunas', 'perlu_diproses', 'telah_diproses', 'dikirim', 'selesai'] as $bucket) {
            if ($svc->bucket($bucket)->firstWhere('id', $so->id)) {
                return $bucket;
            }
        }

        return null;
    }

    public function test_tanpa_pembayaran_masuk_belum_bayar(): void
    {
        $so = $this->so();

        $row = app(FulfillmentReadinessService::class)->bucket('belum_bayar')->firstWhere('id', $so->id);

        $this->assertNotNull($row);
        $this->assertSame('Belum ada pembayaran / DP', $row['reason']);
    }

    public function test_so_draft_ikut_tampil_dan_ditandai(): void
    {
        // Draft dulu tidak terlihat sama sekali di layar packing (query mengunci 'confirmed').
        $so = $this->so(['status' => 'draft']);

        $row = app(FulfillmentReadinessService::class)->bucket('belum_bayar')->firstWhere('id', $so->id);

        $this->assertNotNull($row, 'SO draft harus ikut terlihat di Belum Bayar');
        $this->assertTrue($row['is_draft']);
        $this->assertSame('Masih draft — perlu diposting dulu', $row['reason']);
    }

    public function test_dp_sebagian_tertahan_di_belum_lunas(): void
    {
        $so = $this->so(['paid_amount' => 40000]);

        $this->assertSame('belum_lunas', $this->bucketOf($so));
    }

    public function test_lunas_langsung_siap_proses(): void
    {
        $so = $this->so(['paid_amount' => 100000]);

        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_custom_yang_belum_selesai_produksi_masuk_belum_siap(): void
    {
        $so = $this->soCustom(['paid_amount' => 40000]);

        $row = app(FulfillmentReadinessService::class)->bucket('belum_siap')->firstWhere('id', $so->id);

        $this->assertNotNull($row);
        $this->assertSame('Produksi belum selesai', $row['reason']);
    }

    public function test_pesanan_tempo_lolos_gerbang_belum_lunas(): void
    {
        // Tempo ditetapkan admin di form SO — barangnya boleh jalan walau sisa tagihan ada.
        $so = $this->so(['paid_amount' => 40000, 'is_tempo' => true, 'tempo_days' => 30]);

        $this->assertSame('perlu_diproses', $this->bucketOf($so));
    }

    public function test_proses_menghormati_tempo(): void
    {
        $svc = app(\App\Modules\POS\Services\PosFulfillmentService::class);

        $so = $this->so(['paid_amount' => 40000]);
        try {
            $svc->createInvoiceFromSalesOrder($so);
            $this->fail('SO belum lunas seharusnya ditolak.');
        } catch (\DomainException $e) {
            $this->assertMatchesRegularExpression('/belum lunas/i', $e->getMessage());
        }

        $so->update(['is_tempo' => true, 'tempo_days' => 30]);

        // Setelah ditandai tempo, penolakannya BUKAN lagi soal lunas (boleh gagal karena hal
        // lain, mis. tidak ada item) — yang diuji: gerbang lunas tidak lagi menghadang.
        try {
            $svc->createInvoiceFromSalesOrder($so->refresh());
        } catch (\Throwable $e) {
            $this->assertDoesNotMatchRegularExpression('/belum lunas/i', $e->getMessage());
        }
    }

    public function test_halaman_semua_menampilkan_riwayat_dengan_status(): void
    {
        $lunas  = $this->so(['paid_amount' => 100000]);
        $batal  = $this->so(['status' => 'void']);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.semua'))
            ->assertOk()
            ->assertSee($lunas->order_number)
            ->assertSee($batal->order_number)   // void tetap terlihat untuk audit
            ->assertSee('Siap Proses')
            ->assertSee('Dibatalkan');
    }

    public function test_sub_tab_perlu_diproses_menyaring_per_tahap(): void
    {
        $belumLunas = $this->so(['paid_amount' => 40000]);
        $siap       = $this->so(['paid_amount' => 100000]);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-lunas']))
            ->assertOk()
            ->assertSee($belumLunas->order_number)
            ->assertDontSee($siap->order_number);

        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.perlu-diproses'))
            ->assertOk()
            ->assertSee($siap->order_number)
            ->assertDontSee($belumLunas->order_number);
    }

    public function test_semua_halaman_tab_terbuka(): void
    {
        $this->so(['paid_amount' => 0]);
        $admin = $this->admin();

        foreach ([
            route('pos.fulfillment.belum-bayar'),
            route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-siap']),
            route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-lunas']),
            route('pos.fulfillment.perlu-diproses'),
            route('pos.fulfillment.telah-diproses'),
            route('pos.fulfillment.telah-diproses', ['resi' => 'belum_generate']),
            route('pos.fulfillment.semua'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    /**
     * "Prioritas" bukan cuma kurir instant: pesanan ambil-di-toko juga ada orangnya yang
     * menunggu di tempat. Keduanya harus ikut chip & hitungan yang sama.
     */
    public function test_ambil_di_toko_terhitung_prioritas_bersama_instant(): void
    {
        $instant = $this->so(['paid_amount' => 100000, 'delivery_method' => 'kurir', 'shipping_service_name' => 'Grab Instant']);
        $pickup  = $this->so(['paid_amount' => 100000, 'delivery_method' => 'ambil_toko']);
        $biasa   = $this->so(['paid_amount' => 100000, 'delivery_method' => 'kurir', 'shipping_service_name' => 'JNE REG']);

        $svc = app(FulfillmentReadinessService::class);

        $this->assertSame(
            ['instant' => 1, 'pickup' => 1, 'total' => 2],
            $svc->prioritasCounts('perlu_diproses')
        );

        $ids = $svc->bucket('perlu_diproses', null, ['prioritas' => 1])->pluck('id')->all();
        $this->assertContains($instant->id, $ids);
        $this->assertContains($pickup->id, $ids);
        $this->assertNotContains($biasa->id, $ids, 'Kurir reguler bukan pesanan prioritas');
    }

    /** Urutan daftar: instant dulu (driver sudah dipesan), lalu ambil-toko, baru sisanya. */
    public function test_prioritas_naik_ke_atas_daftar(): void
    {
        // Dibuat terbalik dari urutan yang diharapkan supaya urutan tanggal/id tidak
        // kebetulan menghasilkan hasil yang benar.
        $biasa   = $this->so(['paid_amount' => 100000, 'delivery_method' => 'kurir', 'shipping_service_name' => 'JNE REG']);
        $pickup  = $this->so(['paid_amount' => 100000, 'delivery_method' => 'ambil_toko']);
        $instant = $this->so(['paid_amount' => 100000, 'delivery_method' => 'kurir', 'shipping_service_name' => 'Lalamove Motor']);

        $ids = app(FulfillmentReadinessService::class)->bucket('perlu_diproses')->pluck('id')->all();

        $this->assertSame([$instant->id, $pickup->id, $biasa->id], $ids);
    }

    public function test_tautan_lama_belum_siap_dialihkan(): void
    {
        $this->actingAs($this->admin())
            ->get(route('pos.fulfillment.belum-siap'))
            ->assertRedirect(route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-siap']));
    }
}
