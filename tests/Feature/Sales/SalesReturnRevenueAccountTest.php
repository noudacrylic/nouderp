<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retur atas faktur membatalkan penjualan lewat kontra-pendapatan 4004 Retur Penjualan,
 * bukan dengan mendebit 4001 Penjualan Produk langsung.
 *
 * Kenapa penting: mendebit 4001 membuat omzet menyusut diam-diam — laporan tak pernah bisa
 * memisahkan "bulan ini jual berapa" dari "diretur berapa", padahal rasio itulah yang dipantau.
 * Labanya sendiri tidak berubah: 4004 sama-sama bertipe revenue dan tampil sebagai deduksi
 * di bagian Pendapatan.
 *
 * Akun 6105 Beban Kerugian Retur tetap terpisah dan menampung hal yang berbeda: nilai MODAL
 * barang yang tidak kembali atau kembali rusak.
 */
class SalesReturnRevenueAccountTest extends TestCase
{
    use RefreshDatabase;

    private function akun(string $code, string $name, string $type, string $normal): int
    {
        return Account::firstOrCreate(['code' => $code], [
            'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true,
        ])->id;
    }

    public function test_retur_faktur_mendebit_4004_bukan_4001(): void
    {
        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(),
             'end_date'   => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $this->akun('4001', 'Penjualan Produk', 'revenue', 'credit');
        $this->akun('1120', 'Piutang Usaha', 'asset', 'debit');
        $this->akun('2106', 'Kelebihan Bayar Customer', 'liability', 'credit');
        $this->akun('5001', 'Harga Pokok Penjualan', 'expense', 'debit');
        $this->akun('6105', 'Beban Kerugian Retur', 'expense', 'debit');
        // 4004 lahir dari migrasi, tidak dibuat manual di sini — kalau migrasinya hilang,
        // tes ini yang pertama berteriak.
        $retur4004 = Account::where('code', '4004')->firstOrFail();
        $this->assertSame('revenue', $retur4004->type);
        $this->assertSame('debit', $retur4004->normal_balance);

        $cust = Customer::create(['code' => 'CUST-1', 'name' => 'Toko Sebelah', 'is_active' => true]);
        $wh   = Warehouse::firstOrCreate(['name' => 'Gudang Test']);
        $prod = Product::firstOrCreate(['sku' => 'RET-1'], [
            'name' => 'Produk Retur', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 100000, 'is_active' => true, 'is_sellable' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-RET-1', 'customer_id' => $cust->id, 'warehouse_id' => $wh->id,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 100000, 'grand_total' => 100000, 'status' => 'posted',
        ]);
        $itemId = DB::table('sales_invoice_items')->insertGetId([
            'sales_invoice_id' => $invoice->id, 'product_id' => $prod->id,
            'description' => 'Produk Retur', 'item_type' => 'product',
            'qty' => 1, 'unit_price' => 100000, 'subtotal' => 100000,
            'cogs_unit' => 40000, 'cogs_total' => 40000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Kondisi `damaged`: barangnya tidak masuk persediaan lagi, modalnya ke 6105.
        $return = app(SalesReturnService::class)->post(new SalesReturnDTO(
            customer_id: $cust->id,
            items: [['invoice_item_id' => $itemId, 'qty' => 1, 'condition' => 'damaged']],
            date: now()->toDateString(),
            invoice_id: $invoice->id,
        ));

        $journal = Journal::where('reference_type', 'sales_return')
            ->where('reference_id', $return->id)->firstOrFail();

        $perKode = [];
        foreach (DB::table('journal_lines')->where('journal_id', $journal->id)->get() as $l) {
            $code = Account::find($l->account_id)->code;
            $perKode[$code]['d'] = round(($perKode[$code]['d'] ?? 0) + (float) $l->debit, 2);
            $perKode[$code]['c'] = round(($perKode[$code]['c'] ?? 0) + (float) $l->credit, 2);
        }

        // Nilai JUAL dibatalkan lewat kontra-pendapatan, bukan mengurangi 4001.
        $this->assertEqualsWithDelta(100000, $perKode['4004']['d'] ?? 0, 0.01);
        $this->assertArrayNotHasKey('4001', $perKode, 'Retur tidak boleh lagi mendebit 4001 Penjualan Produk.');

        // Fakturnya belum pernah dibayar, jadi yang dibatalkan adalah TAGIHANNYA — tidak ada
        // uang yang bergerak, dan tidak ada kredit pelanggan yang lahir. Lihat
        // SalesReturnRefundTargetTest untuk tujuan dana saat uangnya memang sudah diterima.
        $this->assertEqualsWithDelta(100000, $perKode['1120']['c'] ?? 0, 0.01);
        $this->assertArrayNotHasKey('2106', $perKode);

        // Nilai MODAL pindah ke 6105 — akun yang berbeda, urusan yang berbeda.
        $this->assertEqualsWithDelta(40000, $perKode['6105']['d'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(40000, $perKode['5001']['c'] ?? 0, 0.01);

        // Di Laba Rugi ia berdiri di bagian Pendapatan sebagai deduksi (saldo negatif),
        // bukan menyusutkan baris Penjualan Produk dan bukan pula jadi Beban Operasional.
        $ringkas = app(\App\Core\Accounting\IncomeStatementService::class)
            ->summary(now()->startOfMonth(), now()->endOfMonth());

        $baris4004 = collect($ringkas['operatingRevenue'])->firstWhere('code', '4004');
        $this->assertNotNull($baris4004, '4004 harus masuk Pendapatan Operasional.');
        $this->assertEqualsWithDelta(-100000, (float) $baris4004->balance, 0.01);
        $this->assertNull(collect($ringkas['opex'])->firstWhere('code', '4004'));
    }
}
