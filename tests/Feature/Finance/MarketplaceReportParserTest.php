<?php

namespace Tests\Feature\Finance;

use App\Core\Accounting\Account;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\MarketplaceOrderFee;
use App\Models\SalesInvoice;
use App\Modules\Finance\Services\MarketplaceReportParser;
use App\Modules\Finance\Services\MarketplaceSettlementService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\MarketplaceFeeBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * File ASLI laporan marketplace untuk Rekonsiliasi. Angka dari laporan asli 1–12 Okt 2026
 * (TikTok income_….xlsx, Shopee my_balance_transaction_report…xlsx), kolomnya dipangkas.
 */
class MarketplaceReportParserTest extends TestCase
{
    use RefreshDatabase;

    private const TIKTOK_HEADER = [
        'ID Pesanan/Penyesuaian', 'Jenis transaksi', 'Waktu pemesanan', 'Waktu pembayaran pesanan', 'Mata uang',
        'Jumlah penyelesaian pembayaran', 'Total Pendapatan', 'Total Biaya', 'Biaya komisi platform', 'Ongkir',
        'Ongkir yang ditalangi penyedia jasa logistik', 'Ongkir yang ditanggung platform', 'Biaya layanan logistik',
        'Komisi Afiliasi', 'Komisi dinamis', 'Biaya layanan Program Growth Xtra', 'Biaya pemrosesan pesanan',
        'PPh Pasal 22 dipungut', 'Jumlah penyesuaian', 'ID pesanan terkait', 'Sumber pesanan',
    ];

    private function tiktok(): Spreadsheet
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->setTitle('Laporan')->fromArray([['Periode', '2026/10/01-2026/10/12']]);
        $ss->createSheet()->setTitle('Detail pesanan')->fromArray([
            self::TIKTOK_HEADER,
            ['586426506895393971', 'Pesanan', '2026/10/05', '2026/10/12', 'IDR', '50391', '69500', '-19109', '-5748', '-990',
             '-14500', '14500', '-990', '-3475', '-5560', '-1738', '-1250', '-348', '0', '/', 'TikTok Shop'],
            ['586201561882985957', 'Pesanan', '2026/09/22', '2026/10/12', 'IDR', '80705', '99995', '-19290', '-8000', '-3540',
             '-69500', '65000', '-3540', '-1630', '-4000', '-2500', '-1250', '0', '0', '/', 'Tokopedia'],
            ['PNY-1', 'Penyesuaian', '', '2026/10/11', 'IDR', '-5000', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '-5000', '/', ''],
        ], null, 'A1', true);

        return $ss;
    }

    private function shopee(): Spreadsheet
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['Laporan'], [], ['Info Rekening'], ['Username (Penjual)', 'noud_acrylic'], [],
            ['Tanggal Transaksi', 'Tipe Transaksi', 'Deskripsi', 'No. Pesanan', 'Jenis Transaksi', 'Jumlah', 'Status', 'Saldo Akhir'],
            ['2026-10-12 04:45:22', 'Penghasilan dari Pesanan', 'Penghasilan dari Pesanan #261005TC2QWPSY', '261005TC2QWPSY', 'Transaksi Masuk', 46559, 'Transaksi Selesai', 1],
            ['2026-10-11 17:14:47', 'Penyesuaian', 'Penyesuaian Saldo Penjual untuk biaya premi Pesanan yang Gagal Terkirim: 2610096HTQN2MD', '-', 'Transaksi Keluar', -230, 'Transaksi Selesai', 1],
            ['2026-10-08 23:07:31', 'Penyesuaian', '[Penambahan Wallet] Pengembalian Biaya Gratis Ongkir Xtra 28 Juli', '-', 'Transaksi Masuk', 193905, 'Transaksi Selesai', 1],
            ['2026-10-06 19:02:41', 'Penyesuaian', 'Kompensasi Jasa Kirim', '260929AF3UR51B', 'Transaksi Masuk', 66000, 'Transaksi Selesai', 1],
            ['2026-10-03 10:00:00', 'Penghasilan dari Pesanan', 'Penghasilan dari Pesanan #260929AF3UR51B', '260929AF3UR51B', 'Transaksi Keluar', -350, 'Transaksi Selesai', 1],
            ['2026-10-05 08:00:00', 'Penarikan Dana', 'Penarikan Dana', '-', 'Transaksi Keluar', -5000000, 'Transaksi Selesai', 1],
        ]);

        return $ss;
    }

    public function test_laporan_tiktok_dirinci_admin_affiliate_dan_pph22(): void
    {
        $r = (new MarketplaceReportParser())->parse($this->tiktok());
        $rows = collect($r['rows'])->keyBy('order_ref');

        $this->assertSame('Laporan penghasilan TikTok/Tokopedia', $r['format']);
        $this->assertCount(2, $rows);
        $a = $rows['586426506895393971'];
        $this->assertSame('tiktok_tokopedia', $a['marketplace']);
        $this->assertSame('2026-10-12', $a['settlement_date']);
        $this->assertEqualsWithDelta(50391, $a['net_amount'], 0.01);
        $this->assertEqualsWithDelta(3475, $a['rincian']['affiliate'], 0.01);
        $this->assertEqualsWithDelta(348, $a['rincian']['pajak'], 0.01);
        $this->assertSame(990.0, (float) $a['rincian']['admin_rincian']['Ongkir & layanan logistik'], 'subtotal Ongkir, anak kolomnya tak dihitung dua kali');
        $this->assertEqualsWithDelta(19109, array_sum($a['rincian']['admin_rincian']) + 3475 + 348, 0.01, '= Total Biaya');

        $this->assertSame(0.0, (float) $rows['586201561882985957']['rincian']['pajak'], 'pesanan 22 Sep: PPh 22 tidak dipungut');
        $this->assertSame(1, $r['dilewati']['Penyesuaian']['n']);
    }

    public function test_mutasi_shopee_per_pesanan_dan_lewati_yang_bukan_pesanan(): void
    {
        $r = (new MarketplaceReportParser())->parse($this->shopee());
        $rows = collect($r['rows'])->keyBy('order_ref');

        $this->assertSame('Mutasi Saldo Penjual Shopee', $r['format']);
        $this->assertEqualsWithDelta(46559, $rows['261005TC2QWPSY']['net_amount'], 0.01);
        $this->assertEqualsWithDelta(-230, $rows['2610096HTQN2MD']['net_amount'], 0.01, 'nomor pesanan diambil dari deskripsi');
        $this->assertEqualsWithDelta(65650, $rows['260929AF3UR51B']['net_amount'], 0.01, 'dua baris satu pesanan dijumlah');
        $this->assertSame('2026-10-06', $rows['260929AF3UR51B']['settlement_date'], 'tanggal paling akhir');
        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(193905, $r['dilewati']['Penyesuaian']['jumlah'], 0.01);
        $this->assertSame(1, $r['dilewati']['Penarikan Dana']['n']);
    }

    public function test_laporan_lazada_dijumlah_per_nomor_pesanan(): void
    {
        $h = ['Periode Laporan', 'Nomor Laporan', 'Tanggal Transaksi ', 'Nama Biaya', 'Jumlah (Termasuk Pajak)', 'VAT Amount',
              'Status Pelepasan Dana', 'Tanggal Dilepas', 'Komentar ', 'Tanggal Pesanan Dibuat', 'Nomor Pesanan', ' ID Pesanan'];
        $baris = fn ($nama, $jumlah) => ['09 Oct 2026', 'X', '09 Oct 2026', $nama, $jumlah, '0', 'Dilepas ke Saldo Toko', '10 Oct 2026', '', '05 Oct 2026', '2703650183060047', '2703650183160047'];
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->setTitle('Income Overview')->fromArray([
            $h, $baris('Omset Penjualan', '34999'), $baris('Order Processing Fee', '-1250'), $baris('Komisi', '-2863'),
            $baris('Biaya Free Shipping Max', '-2801'), $baris('Biaya Promosi Diskon LazKoin', '-777'),
            $baris("Biaya Transaksi

", '-637'), $baris('Pajak Penghasilan Pasal 22', '-175'),
        ], null, 'A1', true);

        $r = (new MarketplaceReportParser())->parse($ss);
        $row = $r['rows'][0];

        $this->assertSame('Laporan penghasilan Lazada', $r['format']);
        $this->assertSame('lazada', $row['marketplace']);
        $this->assertSame('2026-10-10', $row['settlement_date']);
        $this->assertEqualsWithDelta(26496, $row['net_amount'], 0.01);
        $this->assertEqualsWithDelta(175, $row['rincian']['pajak'], 0.01);
        $this->assertEqualsWithDelta(8328, array_sum($row['rincian']['admin_rincian']), 0.01);
        $this->assertArrayHasKey('Biaya Transaksi', $row['rincian']['admin_rincian'], 'nama biaya dirapikan dari baris baru');
    }

    public function test_format_baku_tetap_dikenali_sebagai_bukan_file_asli(): void
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([['marketplace', 'order_ref', 'settlement_date', 'net_amount'], ['shopee', 'X1', '2026-10-01', 1000]]);

        $this->assertNull((new MarketplaceReportParser())->parse($ss));
    }

    public function test_unggah_laporan_tiktok_mengisi_rincian_pesanan_dan_tidak_ditimpa_jubelio(): void
    {
        $akun = fn (string $code, string $type) => Account::firstOrCreate(['code' => $code], ['name' => $code, 'type' => $type, 'normal_balance' => $type === 'asset' ? 'debit' : 'credit', 'is_active' => true])->id;
        $cust = Customer::create(['code' => 'TOKO', 'name' => 'Tokopedia', 'marketplace_code' => 'tokopedia', 'is_marketplace' => true, 'is_active' => true]);
        $cfg = MarketplaceConfig::create([
            'customer_id' => $cust->id, 'admin_fee_percent' => 10, 'admin_fee_fixed' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $akun('1203', 'asset'), 'account_wallet_id' => $akun('1105', 'asset'),
            'tax_percent' => 0.5, 'tax_start_date' => '2026-10-02',
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $so = SalesOrder::create([
            'order_number' => 'SO-TT1', 'customer_id' => $cust->id, 'warehouse_id' => $wh, 'customer_po_number' => 'TP-586426506895393971-127339',
            'order_date' => '2026-10-05', 'status' => 'confirmed', 'subtotal' => 69500, 'grand_total' => 69500,
        ]);
        $inv = SalesInvoice::create([
            'invoice_number' => 'INV-TT1', 'sales_order_id' => $so->id, 'customer_id' => $cust->id, 'warehouse_id' => $wh,
            'invoice_date' => '2026-10-06', 'subtotal' => 69500, 'grand_total' => 69500, 'status' => 'posted',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'tt') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($this->tiktok()))->save($path);
        $svc = app(MarketplaceSettlementService::class);
        $parsed = $svc->parseStandardFormat($path, 'xlsx');
        @unlink($path);

        $this->assertSame([$cfg->id], array_keys($parsed['rows_per_config']), 'label tiktok_tokopedia → config Tokopedia');
        $this->assertSame(1, $svc->simpanRincianLaporan($parsed['rows_per_config']));

        $fee = MarketplaceOrderFee::where('sales_order_id', $so->id)->first();
        $this->assertSame('laporan', $fee->sumber);
        $this->assertTrue($fee->final);
        $this->assertEqualsWithDelta(3475, $fee->affiliate, 0.01);
        $this->assertEqualsWithDelta(50391, $fee->saldo_masuk, 0.01);
        $this->assertEqualsWithDelta(0, $fee->lain, 0.01);
        $this->assertEqualsWithDelta(348, (float) $inv->fresh()->mp_tax_withheld, 0.01, 'PPh 22 asli ke Laporan Pajak');

        // Angka gabungan Jubelio TikTok yang datang belakangan tidak menimpa laporan.
        $b = app(MarketplaceFeeBreakdownService::class)->dariJubelio(['sub_total' => 69500, 'grand_total' => 69500,
            'escrow_list' => ['settlement_amount' => 50391, 'fee_and_tax_amount' => -18119, 'shipping_cost_amount' => -990]], 69500, true, $cfg, '2026-10-05');
        app(MarketplaceFeeBreakdownService::class)->simpan($so, $b);
        $this->assertSame('laporan', $fee->fresh()->sumber);
        $this->assertEqualsWithDelta(3475, $fee->fresh()->affiliate, 0.01);
    }
}
