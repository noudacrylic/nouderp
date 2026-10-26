<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeWalletTransaction as Mutasi;
use App\Modules\Marketplace\Shopee\Services\MutasiShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Tahap 1a: mutasi Saldo Penjual Shopee (file Seller Centre) dicocokkan dengan jurnal 1104.
 */
class MutasiShopeeTest extends TestCase
{
    use RefreshDatabase;

    private int $akun1104;
    private int $akunBank;
    private int $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akun1104 = DB::table('accounts')->insertGetId(['code' => '1104', 'name' => 'Saldo Penjualan Shopee', 'type' => 'asset', 'normal_balance' => 'debit']);
        $this->akunBank = DB::table('accounts')->insertGetId(['code' => '1102', 'name' => 'BCA', 'type' => 'asset', 'normal_balance' => 'debit']);
        $this->periode  = DB::table('accounting_periods')->insertGetId(['year' => 2026, 'month' => 10, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'open']);
    }

    /** File seperti unduhan Seller Centre: judul, ringkasan, lalu rincian terbaru di atas. */
    private function file(array $baris): string
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->fromArray([['Laporan'], [], ['Username (Penjual)', 'noud_acrylic'], [], ['Rincian Transaksi'], [],
            ['Tanggal Transaksi', 'Tipe Transaksi', 'Deskripsi', 'No. Pesanan', 'Jenis Transaksi', 'Jumlah', 'Status', 'Saldo Akhir']]);
        $sh->fromArray(array_reverse($baris), null, 'A8');
        $path = tempnam(sys_get_temp_dir(), 'mutasi') . '.xlsx';
        (new Xlsx($ss))->save($path);
        return $path;
    }

    private function jurnal(string $tanggal, string $deskripsi, float $nilai, string $status = 'posted'): int
    {
        static $n = 0;
        $n++;
        $id = DB::table('journals')->insertGetId([
            'journal_number' => "JRN-UJI-{$n}", 'date' => $tanggal, 'reference_type' => 'uji', 'reference_id' => $n,
            'period_id' => $this->periode, 'description' => $deskripsi, 'status' => $status,
        ]);
        DB::table('journal_lines')->insert([
            ['journal_id' => $id, 'account_id' => $this->akun1104, 'debit' => max($nilai, 0), 'credit' => max(-$nilai, 0)],
            ['journal_id' => $id, 'account_id' => $this->akunBank, 'debit' => max(-$nilai, 0), 'credit' => max($nilai, 0)],
        ]);
        return $id;
    }

    /** Skenario dasar: 2 pesanan, 1 retur pasca-pelepasan, 1 penarikan, 1 iklan, 1 SPinjam. */
    private function barisDasar(): array
    {
        return [
            ['2026-10-01 09:00:00', 'Penghasilan dari Pesanan', 'Penghasilan dari Pesanan #2610015AAAAAAA', '2610015AAAAAAA', 'Transaksi Masuk', 100000, 'Transaksi Selesai', 100000],
            ['2026-10-01 10:00:00', 'Penghasilan dari Pesanan', 'Penghasilan dari Pesanan #2610015BBBBBBB', '2610015BBBBBBB', 'Transaksi Masuk', 50000, 'Transaksi Selesai', 150000],
            ['2026-10-02 08:00:00', 'Penyesuaian', 'Penyesuaian saldo dilakukan untuk pesanan 2610015BBBBBBB karena terdapat Pengembalian Barang/Dana setelah dana dilepaskan', '-', 'Transaksi Keluar', -50000, 'Transaksi Selesai', 100000],
            ['2026-10-03 08:00:00', 'Penarikan Dana', 'Penarikan Dana', '-', 'Transaksi Keluar', -60000, 'Transaksi Selesai', 40000],
            ['2026-10-03 09:00:00', 'Pembayaran dengan Saldo Penjual', 'Isi Ulang Saldo Iklan/Koin Penjual', '-', 'Transaksi Keluar', -30000, 'Transaksi Selesai', 10000],
            ['2026-10-04 09:00:00', 'SPinjam untuk Penjual', 'Pencairan SPinjam untuk Penjual', '-', 'Transaksi Masuk', 500000, 'Transaksi Selesai', 510000],
        ];
    }

    private function svc(): MutasiShopeeService
    {
        return app(MutasiShopeeService::class);
    }

    public function test_impor_mengenali_kategori_dan_nomor_pesanan_dari_deskripsi(): void
    {
        $r = $this->svc()->impor($this->file($this->barisDasar()));

        $this->assertSame(6, $r['baru']);
        $this->assertSame(['2026-10-01', '2026-10-04'], [$r['dari'], $r['sampai']]);
        $kat = Mutasi::orderBy('waktu')->pluck('kategori')->all();
        $this->assertSame(['pesanan', 'pesanan', 'penyesuaian_pesanan', 'penarikan', 'iklan', 'pinjaman'], $kat);
        $this->assertSame('2610015BBBBBBB', Mutasi::where('kategori', 'penyesuaian_pesanan')->value('order_sn'));
    }

    public function test_unggah_ulang_rentang_bertumpuk_tidak_membuat_kembar(): void
    {
        $this->svc()->impor($this->file($this->barisDasar()));
        $r = $this->svc()->impor($this->file(array_slice($this->barisDasar(), 2)));

        $this->assertSame(0, $r['baru']);
        $this->assertSame(6, Mutasi::count());
    }

    public function test_pencocokan_per_pesanan_dan_per_nominal(): void
    {
        $this->jurnal('2026-09-30', 'Marketplace Settlement - SP-2610015AAAAAAA', 100000);        // tanggal beda, tetap cocok
        $this->jurnal('2026-10-01', 'Marketplace Settlement - SP-2610015BBBBBBB', 50000);         // retur belum dijurnal → selisih
        $this->jurnal('2026-10-05', 'Transfer 1104 ke BCA', -60000);                              // penarikan, beda 2 hari
        $this->jurnal('2026-10-03', 'Marketplace Settlement - SP-2610015AAAAAAA (void)', 999, 'void'); // void diabaikan
        $this->svc()->impor($this->file($this->barisDasar()));

        $hasil = $this->svc()->cocokkan();

        $c = Mutasi::orderBy('waktu')->get()->keyBy('kategori');
        $this->assertSame(Mutasi::COCOK, Mutasi::where('order_sn', '2610015AAAAAAA')->value('cocok'));
        $this->assertSame(Mutasi::SELISIH, $c['penyesuaian_pesanan']->cocok);
        $this->assertStringContainsString('Shopee Rp0 vs jurnal Rp50.000', $c['penyesuaian_pesanan']->cocok_catatan);
        $this->assertSame(Mutasi::COCOK, $c['penarikan']->cocok);
        $this->assertStringContainsString('beda 2 hari', $c['penarikan']->cocok_catatan);
        $this->assertSame(Mutasi::BELUM, $c['iklan']->cocok);
        $this->assertSame(Mutasi::BELUM, $c['pinjaman']->cocok);
        $this->assertSame([Mutasi::BELUM => 2, Mutasi::COCOK => 2, Mutasi::SELISIH => 2], collect($hasil)->sortKeys()->all());
    }

    public function test_satu_jurnal_tak_dipakai_dua_baris_mutasi(): void
    {
        $this->jurnal('2026-10-03', 'Transfer 1104 ke BCA', -60000);
        $baris = $this->barisDasar();
        $baris[] = ['2026-10-03 15:00:00', 'Penarikan Dana', 'Penarikan Dana', '-', 'Transaksi Keluar', -60000, 'Transaksi Selesai', 450000];
        $this->svc()->impor($this->file($baris));

        $this->svc()->cocokkan();

        $this->assertSame([Mutasi::BELUM, Mutasi::COCOK], Mutasi::where('kategori', 'penarikan')->pluck('cocok')->sort()->values()->all());
    }

    public function test_saldo_harian_menunjukkan_hari_selisih_muncul(): void
    {
        $this->jurnal('2026-10-01', 'Marketplace Settlement - SP-2610015AAAAAAA', 100000);
        $this->jurnal('2026-10-01', 'Marketplace Settlement - SP-2610015BBBBBBB', 50000);
        $this->jurnal('2026-10-03', 'Transfer 1104 ke BCA', -60000);
        $this->svc()->impor($this->file($this->barisDasar()));

        $h = collect($this->svc()->harian())->keyBy('tanggal');

        $this->assertEquals(0, $h['2026-10-01']['selisih']);
        $this->assertEquals(50000, $h['2026-10-02']['selisih'], 'Retur 2 Okt tak dijurnal.');
        $this->assertEquals(50000, $h['2026-10-02']['berubah']);
        $this->assertEquals(80000, $h['2026-10-03']['selisih'], '+ iklan 30rb tak dijurnal.');
        $this->assertEquals(10000, $h['2026-10-03']['shopee']);
        $this->assertEquals(-420000, $h['2026-10-04']['selisih'], 'SPinjam 500rb tak dijurnal.');
    }

    public function test_halaman_unggah_dan_tampil(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $file = new UploadedFile($this->file($this->barisDasar()), 'my_balance_transaction_report.shopee.xlsx', null, null, true);

        $this->actingAs($user)->post(route('finance.cash-bank.settlements.mutasi-shopee.impor'), ['files' => [$file]])
            ->assertSessionHas('success');

        $this->actingAs($user)->get(route('finance.cash-bank.settlements.mutasi-shopee.index'))
            ->assertOk()->assertSee('Saldo Shopee terakhir')->assertSee('Rp510.000');
        $this->actingAs($user)->get(route('finance.cash-bank.settlements.mutasi-shopee.index', ['tab' => 'transaksi', 'kategori' => 'pinjaman']))
            ->assertOk()->assertSee('Pencairan SPinjam untuk Penjual')->assertSee('Belum dijurnal');
    }

    public function test_file_lain_ditolak(): void
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([['Nama', 'Harga'], ['x', 1]]);
        $path = tempnam(sys_get_temp_dir(), 'lain') . '.xlsx';
        (new Xlsx($ss))->save($path);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->impor($path);
    }
}
