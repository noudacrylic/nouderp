<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MidtransTransaction;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\BillingReminderService;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penagihan pesanan yang tautan bayarnya sudah dibuat tapi belum dibayar.
 *
 * Dua kerugian dijaga sekaligus, dan keduanya nyata. Pesanan yang menggantung
 * MENGUNCI STOK: barang yang bisa dijual ke orang lain tertahan berminggu-minggu
 * demi pembeli yang sudah lama hilang. Sebaliknya, salah membatalkan pesanan
 * yang sebenarnya sah adalah kerusakan yang tidak bisa ditarik kembali — uang
 * pembeli, barang yang sudah dikerjakan, kepercayaan.
 *
 * Karena itu yang paling keras diuji di sini bukan "tagihannya terkirim",
 * melainkan SIAPA YANG TIDAK BOLEH DISENTUH: pesanan yang sudah ada uangnya,
 * pesanan tempo, dan pesanan marketplace.
 */
class TagihPembayaranTest extends TestCase
{
    use RefreshDatabase;

    private function pelanggan(array $lain = []): Customer
    {
        return Customer::create(array_merge([
            'code'      => 'CUST-' . fake()->unique()->numberBetween(1000, 9999),
            'name'      => 'Budi',
            'phone'     => '628111111111',
            'is_active' => true,
            'wa_opt_in' => true,
        ], $lain));
    }

    private function gudang(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['name' => 'Gudang Jual'],
            ['is_active' => true, 'is_sellable' => true]
        );
    }

    private function pesanan(Customer $c, array $lain = []): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'order_number' => 'SO-TEST-' . fake()->unique()->numberBetween(1000, 9999),
            'customer_id'  => $c->id,
            'warehouse_id' => $this->gudang()->id,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'grand_total'  => 500000,
            'paid_amount'  => 0,
        ], $lain));
    }

    /** Tautan bayar berumur $umur hari. */
    private function tautan(SalesOrder $so, int $umur): MidtransTransaction
    {
        $trx = MidtransTransaction::create([
            'order_id'       => 'ORD-' . $so->id . '-' . $umur,
            'sales_order_id' => $so->id,
            'customer_id'    => $so->customer_id,
            'source'         => 'link',
            'channel'        => 'snap',
            'link_token'     => 'tok' . $so->id . $umur,
            'gross_amount'   => 500000,
            'base_amount'    => 500000,
            'status'         => 'pending',
        ]);

        $trx->forceFill(['created_at' => now()->subDays($umur)])->save();

        return $trx;
    }

    private function tagihan(): BillingReminderService
    {
        return app(BillingReminderService::class);
    }

    private function barisTagihan(SalesOrder $so)
    {
        return CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_TAGIHAN)
            ->where('sales_order_id', $so->id);
    }

    /* --------------------------------------------------------------- jadwal */

    public function test_belum_ditagih_di_hari_yang_sama(): void
    {
        $so = $this->pesanan($this->pelanggan());
        $this->tautan($so, 0);

        $this->assertSame(0, $this->tagihan()->jalankan()['ditagih']);
        $this->assertSame(0, $this->barisTagihan($so)->count());
    }

    public function test_tiga_hari_pertama_ditagih_tiap_hari(): void
    {
        $so   = $this->pesanan($this->pelanggan());
        $link = $this->tautan($so, 1);

        $this->assertSame(1, $this->tagihan()->jalankan()['ditagih']);

        // Hari yang sama, putaran kedua → tidak ada pesan kedua.
        $this->assertSame(0, $this->tagihan()->jalankan()['ditagih']);
        $this->assertSame(1, $this->barisTagihan($so)->count());

        // Besoknya titik jadwal berikutnya terlewati → satu pesan lagi.
        $link->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->assertSame(1, $this->tagihan()->jalankan()['ditagih']);
        $this->assertSame(2, $this->barisTagihan($so)->count());
    }

    /**
     * Cron yang mati beberapa hari TIDAK boleh memuntahkan semua tagihan yang
     * terlewat sekaligus. Yang berangkat cuma titik jadwal terakhir.
     */
    public function test_jadwal_yang_terlewat_tidak_dikirim_berbondong(): void
    {
        $so = $this->pesanan($this->pelanggan());
        $this->tautan($so, 12);

        $this->tagihan()->jalankan();

        $this->assertSame(1, $this->barisTagihan($so)->count());
        $this->assertSame('so:' . $so->id . ':tagih:h10', $this->barisTagihan($so)->first()->dedupe_key);
    }

    public function test_sesudah_hari_ketiga_ditagih_mingguan(): void
    {
        $so   = $this->pesanan($this->pelanggan());
        $link = $this->tautan($so, 5);

        // Hari ke-5 masih memakai titik hari ke-3; belum ada titik baru.
        $this->tagihan()->jalankan();
        $this->assertSame(1, $this->barisTagihan($so)->count());

        // Hari ke-9 belum sampai titik mingguan berikutnya (hari ke-10).
        $link->forceFill(['created_at' => now()->subDays(9)])->save();
        $this->assertSame(0, $this->tagihan()->jalankan()['ditagih']);

        $link->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->assertSame(1, $this->tagihan()->jalankan()['ditagih']);
    }

    /* ------------------------------------------------------------ isi pesan */

    public function test_pesan_membawa_tautan_bayar_dan_tanggal_batas(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $so   = $this->pesanan($this->pelanggan());
        $link = $this->tautan($so, 1);

        $this->tagihan()->jalankan();

        $isi = $this->barisTagihan($so)->firstOrFail()->template_body;

        $this->assertSame('Budi', $isi[0]);
        $this->assertSame($so->order_number, $isi[1]);
        $this->assertSame('500.000', $isi[2]);
        $this->assertStringContainsString('/pay/' . $link->link_token, $isi[3]);
        // Batasnya dihitung dari lahirnya TAUTAN (31 Agu + 28 hari), bukan dari hari ini.
        $this->assertSame('28 September 2026', $isi[4]);

        Carbon::setTestNow();
    }

    /* -------------------------------------------------------- yang dilewati */

    /**
     * Pesanan yang SUDAH ADA UANGNYA tidak ditagih maupun dibatalkan. DP sudah
     * masuk, barangnya mungkin sudah dikerjakan, dan void-nya pun akan tertahan
     * oleh pembayaran yang aktif.
     */
    public function test_pesanan_yang_sudah_ada_dp_tidak_disentuh(): void
    {
        $so = $this->pesanan($this->pelanggan(), ['paid_amount' => 150000]);
        $this->tautan($so, 40);

        $h = $this->tagihan()->jalankan();

        $this->assertSame(0, $h['ditagih']);
        $this->assertSame(0, $h['dibatalkan']);
        $this->assertSame('confirmed', $so->fresh()->status);
    }

    /** Pesanan TEMPO memang sengaja belum dibayar sampai jatuh temponya sendiri. */
    public function test_pesanan_tempo_tidak_ditagih_maupun_dibatalkan(): void
    {
        $so = $this->pesanan($this->pelanggan(), [
            'is_tempo'       => true,
            'tempo_due_date' => now()->addMonths(2)->toDateString(),
        ]);
        $this->tautan($so, 40);

        $h = $this->tagihan()->jalankan();

        $this->assertSame(0, $h['ditagih']);
        $this->assertSame(0, $h['dibatalkan']);
        $this->assertSame('confirmed', $so->fresh()->status);
    }

    /** Pembeli marketplace tidak boleh dihubungi di luar platform. */
    public function test_pesanan_marketplace_tidak_ditagih(): void
    {
        $so = $this->pesanan($this->pelanggan(['is_marketplace' => true]));
        $this->tautan($so, 3);

        $this->assertSame(0, $this->tagihan()->jalankan()['ditagih']);
        $this->assertSame(0, $this->barisTagihan($so)->count());
    }

    public function test_pelanggan_tanpa_opt_in_tidak_ditagih(): void
    {
        $so = $this->pesanan($this->pelanggan(['wa_opt_in' => false]));
        $this->tautan($so, 3);

        $this->assertSame(0, $this->tagihan()->jalankan()['ditagih']);
    }

    /* ------------------------------------------------------------- pembatalan */

    public function test_lewat_empat_minggu_dibatalkan_dan_reservasi_dilepas(): void
    {
        $so = $this->pesanan($this->pelanggan());
        $this->tautan($so, 28);

        $gudang = $this->gudang();
        $p      = Product::create(['sku' => 'BMA-40', 'name' => 'Box Mahar', 'is_active' => true, 'is_sellable' => true]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => 10]);

        $reservasi = StockReservation::create([
            'product_id'     => $p->id,
            'warehouse_id'   => $gudang->id,
            'qty'            => 3,
            'status'         => 'active',
            'sales_order_id' => $so->id,
        ]);

        $this->assertSame(1, $this->tagihan()->jalankan()['dibatalkan']);

        $this->assertSame('void', $so->fresh()->status);
        $this->assertSame('cancelled', $reservasi->fresh()->status);
        // Alasannya tertulis di pesanannya sendiri, bukan cuma di log.
        $this->assertStringContainsString('Auto-batal', (string) $so->fresh()->notes);
    }

    /** Sudah dibatalkan → putaran berikutnya tidak menyentuhnya lagi. */
    public function test_pembatalan_tidak_diulang(): void
    {
        $so = $this->pesanan($this->pelanggan());
        $this->tautan($so, 30);

        $this->tagihan()->jalankan();
        $this->assertSame(0, $this->tagihan()->jalankan()['dibatalkan']);
    }

    /* ----------------------------------------------------------- uji coba */

    /** `--dry-run` memperlihatkan rencananya tanpa menyentuh apa pun. */
    public function test_uji_coba_tidak_membatalkan_apa_pun(): void
    {
        $so = $this->pesanan($this->pelanggan());
        $this->tautan($so, 30);

        $this->artisan('crm:tagih-pembayaran --dry-run')->assertSuccessful();

        $this->assertSame('confirmed', $so->fresh()->status);
        $this->assertSame(0, CrmOutboxMessage::count());
    }

    public function test_perintah_terjadwal_menagih(): void
    {
        $so = $this->pesanan($this->pelanggan());
        $this->tautan($so, 2);

        $this->artisan('crm:tagih-pembayaran')->assertSuccessful();

        $this->assertSame(1, $this->barisTagihan($so)->count());
    }
}
