<?php

namespace Tests\Feature\Dashboard;

use App\Core\Accounting\Account;
use App\Core\Period\AccountingPeriod;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Services\DashboardAuditService;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deret "Penjualan" di grafik dashboard dihitung dari PESANAN SELESAI.
 *
 * Bukan dari tanggal faktur. Pesanan marketplace difakturkan di muka — faktur
 * terbit begitu pesanannya masuk, sebelum sebutir barang pun dikirim — jadi
 * tanggal faktur berhenti menjawab "bulan ini kita menjual berapa": satu hari
 * ramai di Shopee melonjakkan grafik untuk barang yang baru dikirim minggu
 * depan, dan sebagiannya batal.
 *
 * Yang dijaga di sini bukan angkanya melainkan TANGGALNYA, dan satu hal yang
 * paling gampang patah diam-diam: nilai faktur yang tergandakan oleh join.
 */
class PenjualanPesananSelesaiTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function pelanggan(bool $marketplace = false): Customer
    {
        return Customer::create([
            'code'           => 'CUST-' . uniqid(),
            'name'           => $marketplace ? 'Shopee Official' : 'Toko Budi',
            'is_marketplace' => $marketplace,
            'is_active'      => true,
        ]);
    }

    /** SO + faktur posted. Nilai brutonya (subtotal − diskon) = $nilai. */
    private function pesanan(string $tglFaktur, float $nilai = 100000, array $attrs = [], bool $marketplace = false): SalesOrder
    {
        $so = SalesOrder::create(array_merge([
            'order_number'         => 'SO-' . uniqid(),
            'customer_id'          => $this->pelanggan($marketplace)->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => $tglFaktur,
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'subtotal'             => $nilai,
            'grand_total'          => $nilai,
            'paid_amount'          => $nilai,
            'delivery_method'      => 'kurir',
        ], $attrs));

        SalesInvoice::create([
            'invoice_number'  => 'INV-' . uniqid(),
            'sales_order_id'  => $so->id,
            'customer_id'     => $so->customer_id,
            'warehouse_id'    => $so->warehouse_id,
            'invoice_date'    => $tglFaktur,
            'delivery_method' => $so->delivery_method,
            'status'          => 'posted',
            'subtotal'        => $nilai,
            'grand_total'     => $nilai,
        ]);

        return $so;
    }

    private function suratJalan(SalesOrder $so, ?string $sampai): SalesDelivery
    {
        return SalesDelivery::create([
            'delivery_number' => 'SJ-' . uniqid(),
            'sales_order_id'  => $so->id,
            'warehouse_id'    => $so->warehouse_id,
            'delivery_method' => 'kurir',
            'delivery_date'   => $so->order_date,
            'status'          => 'posted',
            'delivered_at'    => $sampai,
        ]);
    }

    /** Deret harian bulan ini, dipetakan tanggal => nilai (hanya yang tidak nol). */
    private function deret(): array
    {
        $d = app(DashboardService::class)->salesSeries('monthly');

        $hasil = [];

        foreach ($d['labels'] as $i => $label) {
            if ((float) $d['penjualan'][$i] !== 0.0) {
                $hasil[(int) $label] = (float) $d['penjualan'][$i];
            }
        }

        return $hasil;
    }

    /* ----------------------------------------------------------- marketplace */

    public function test_pesanan_marketplace_dihitung_pada_tanggal_tuntas_bukan_tanggal_faktur(): void
    {
        $terbit = now()->startOfMonth()->addDays(1);      // faktur terbit di muka
        $tuntas = now()->startOfMonth()->addDays(9);      // barangnya sampai belakangan

        $so = $this->pesanan($terbit->toDateString(), 250000, [], marketplace: true);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 901,
            'jubelio_salesorder_no' => 'TP-901',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'completed',
            'wms_completed_at'      => $tuntas,
        ]);

        $deret = $this->deret();

        $this->assertArrayNotHasKey((int) $terbit->format('j'), $deret, 'tanggal faktur tidak boleh ikut');
        $this->assertSame(250000.0, $deret[(int) $tuntas->format('j')] ?? null);
    }

    /**
     * `mp_completed_at` MENANG atas `wms_completed_at`, dan bedanya bukan soal
     * ketelitian: `wms_completed_at` menyala saat KITA selesai memproses
     * pesanan (barang keluar gudang), kerap berhari-hari sebelum pembeli
     * menerimanya. Grafik yang memakainya menaruh omzet di minggu yang salah.
     */
    public function test_tanggal_selesai_marketplace_menang_atas_tanggal_proses_gudang(): void
    {
        $terbit  = now()->startOfMonth()->addDays(1);
        $keluar  = now()->startOfMonth()->addDays(4);   // kita selesai memproses
        $diterima = now()->startOfMonth()->addDays(11); // marketplace menyatakan selesai

        $so = $this->pesanan($terbit->toDateString(), 300000, [], marketplace: true);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 903,
            'jubelio_salesorder_no' => 'TP-903',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'completed',
            'wms_completed_at'      => $keluar,
            'mp_completed_at'       => $diterima,
        ]);

        $this->assertSame([(int) $diterima->format('j') => 300000.0], $this->deret());
    }

    /**
     * Pesanan marketplace yang BELUM tuntas tidak masuk hitungan sama sekali —
     * bukan masuk dengan tanggal seadanya. Uang yang belum tentu jadi milik kita
     * lebih berbahaya di grafik daripada tidak ada angkanya.
     */
    public function test_pesanan_marketplace_belum_tuntas_tidak_dihitung(): void
    {
        $so = $this->pesanan(now()->startOfMonth()->addDays(2)->toDateString(), 500000, [], marketplace: true);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 902,
            'jubelio_salesorder_no' => 'TP-902',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'processed',
        ]);

        $this->assertSame([], $this->deret());
    }

    /* -------------------------------------------------------- non-marketplace */

    public function test_pesanan_kurir_dihitung_saat_surat_jalannya_ditandai_sampai(): void
    {
        $terbit = now()->startOfMonth()->addDays(3);
        $sampai = now()->startOfMonth()->addDays(6);

        $so = $this->pesanan($terbit->toDateString(), 90000);
        $this->suratJalan($so, $sampai->toDateString());

        $deret = $this->deret();

        $this->assertArrayNotHasKey((int) $terbit->format('j'), $deret);
        $this->assertSame(90000.0, $deret[(int) $sampai->format('j')] ?? null);
    }

    public function test_paket_yang_masih_di_jalan_belum_dihitung(): void
    {
        $so = $this->pesanan(now()->startOfMonth()->addDays(3)->toDateString(), 90000);
        $this->suratJalan($so, null);

        $this->assertSame([], $this->deret());
    }

    /**
     * Kirim bertahap: DUA surat jalan untuk satu faktur tidak boleh membuat
     * nilainya terhitung dua kali. Join lugas ke sales_deliveries persis
     * melakukan itu — omzet naik dua kali lipat tanpa satu dokumen tambahan.
     */
    public function test_dua_surat_jalan_tidak_menggandakan_nilai_faktur(): void
    {
        $awal  = now()->startOfMonth()->addDays(4);
        $akhir = now()->startOfMonth()->addDays(8);

        $so = $this->pesanan($awal->toDateString(), 120000);
        $this->suratJalan($so, $awal->toDateString());
        $this->suratJalan($so, $akhir->toDateString());

        $deret = $this->deret();

        // Satu nilai saja, dan ia duduk di SJ terakhir — pesanan baru benar-benar
        // selesai ketika kiriman terakhirnya sampai.
        $this->assertSame([(int) $akhir->format('j') => 120000.0], $deret);
    }

    public function test_ambil_toko_dihitung_saat_barangnya_diambil(): void
    {
        $terbit  = now()->startOfMonth()->addDays(5);
        $diambil = now()->startOfMonth()->addDays(8);

        $this->pesanan($terbit->toDateString(), 70000, [
            'delivery_method' => 'ambil_toko',
            'pickup_status'   => 'picked_up',
            'picked_up_at'    => $diambil,
        ]);

        $this->assertSame([(int) $diambil->format('j') => 70000.0], $this->deret());
    }

    /** Fakturnya sudah terbit, tapi barangnya masih menunggu di rak. */
    public function test_ambil_toko_yang_belum_diambil_belum_dihitung(): void
    {
        $this->pesanan(now()->startOfMonth()->addDays(5)->toDateString(), 70000, [
            'delivery_method' => 'ambil_toko',
            'pickup_status'   => 'pending',
        ]);

        $this->assertSame([], $this->deret());
    }

    /** Kasir: faktur tanpa SO. Barangnya diserahkan saat itu juga. */
    public function test_faktur_kasir_tanpa_sales_order_dihitung_pada_tanggal_fakturnya(): void
    {
        $tgl = now()->startOfMonth()->addDays(7);

        SalesInvoice::create([
            'invoice_number' => 'INV-KASIR-' . uniqid(),
            'customer_id'    => $this->pelanggan()->id,
            'warehouse_id'   => $this->warehouseId(),
            'invoice_date'   => $tgl->toDateString(),
            'status'         => 'posted',
            'subtotal'       => 45000,
            'grand_total'    => 45000,
        ]);

        $this->assertSame([(int) $tgl->format('j') => 45000.0], $this->deret());
    }

    public function test_faktur_draft_tidak_ikut(): void
    {
        $tgl = now()->startOfMonth()->addDays(5);

        SalesInvoice::create([
            'invoice_number' => 'INV-DRAFT-' . uniqid(),
            'customer_id'    => $this->pelanggan()->id,
            'warehouse_id'   => $this->warehouseId(),
            'invoice_date'   => $tgl->toDateString(),
            'status'         => 'draft',
            'subtotal'       => 900000,
            'grand_total'    => 900000,
        ]);

        $this->assertSame([], $this->deret());
    }

    /* --------------------------------------------------- fee admin marketplace */

    /**
     * Fee admin MENGIKUTI penjualannya, bukan tanggal jurnalnya.
     *
     * Fee faktur marketplace dibukukan pada tanggal FAKTUR — yang untuk
     * marketplace adalah tanggal pesanannya masuk. Selama penjualannya berdiri
     * di tanggal pesanan SELESAI, keduanya ada di sumbu waktu yang berbeda, dan
     * hari yang fee-nya besar tapi belum ada pesanan selesai menggambar batang
     * MINUS: omzet negatif untuk hari kita berjualan seperti biasa.
     */
    public function test_fee_marketplace_ikut_pindah_ke_tanggal_selesai(): void
    {
        $terbit = now()->startOfMonth()->addDays(2);
        $tuntas = now()->startOfMonth()->addDays(9);

        $so  = $this->pesanan($terbit->toDateString(), 200000, [], marketplace: true);
        $inv = SalesInvoice::where('sales_order_id', $so->id)->firstOrFail();

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 904,
            'jubelio_salesorder_no' => 'TP-904',
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'completed',
            'mp_completed_at'       => $tuntas,
        ]);

        // Jurnal fee-nya bertanggal FAKTUR, seperti di sistem sungguhan.
        $this->jurnalFee($inv->id, $terbit->toDateString(), 30000);

        $deret = $this->deret();

        // Tidak ada hari minus, dan fee-nya terpotong di hari pesanan selesai.
        $this->assertSame([], array_filter($deret, fn ($v) => $v < 0));
        $this->assertSame(170000.0, $deret[(int) $tuntas->format('j')] ?? null);
    }

    /** Jurnal beban fee admin marketplace (akun contra-revenue) atas satu faktur. */
    private function jurnalFee(int $invoiceId, string $tanggal, float $nilai): void
    {
        $akun = Account::firstOrCreate(['code' => '5002'], [
            'name'           => 'Beban Admin Marketplace',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_active'      => true,
        ]);

        $periode = AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(),
             'end_date'   => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $jurnalId = DB::table('journals')->insertGetId([
            'journal_number'  => 'JV-' . uniqid(),
            'date'            => $tanggal,
            'period_id'       => $periode->id,
            'reference_type'  => 'sales_invoice',
            'reference_id'    => $invoiceId,
            'status'          => 'posted',
            'posted_at'       => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('journal_lines')->insert([
            'journal_id' => $jurnalId,
            'account_id' => $akun->id,
            'debit'      => $nilai,
            'credit'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------- drill-down */

    /**
     * Daftar yang terbuka saat angka kartu diklik WAJIB berjumlah sama dengan
     * kartunya. Angka yang tidak bisa dihitung ulang dengan mata berhenti
     * dipercaya, termasuk saat ia benar.
     */
    public function test_rincian_penjualan_memakai_tanggal_selesai_yang_sama(): void
    {
        $terbit = now()->startOfMonth()->addDays(2);
        $sampai = now()->startOfMonth()->addDays(6);

        $so = $this->pesanan($terbit->toDateString(), 150000);
        $this->suratJalan($so, $sampai->toDateString());

        // Belum selesai → tidak boleh muncul di daftar.
        $this->pesanan(now()->startOfMonth()->addDays(2)->toDateString(), 999000);

        $rincian = app(DashboardAuditService::class)->build('penjualan', ['period' => 'monthly']);

        $this->assertCount(1, $rincian['rows']);
        $this->assertSame(150000, (int) $rincian['total']);
        $this->assertStringStartsWith($sampai->toDateString(), (string) $rincian['rows'][0]['date']);
    }
}
