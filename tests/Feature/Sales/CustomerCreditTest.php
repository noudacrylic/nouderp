<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Models\Customer;
use App\Models\CustomerOverpayment;
use App\Modules\Sales\Models\CustomerCredit;
use App\Modules\Sales\Services\CustomerCreditPaymentService;
use App\Modules\Sales\Services\CustomerCreditService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kredit pelanggan yang diberikan tanpa uang bergerak.
 *
 * Kasus yang melahirkannya: pembeli marketplace menukar ukuran atas penjualan yang sudah
 * selesai (bahkan yang fakturnya tak ada di ERP). Barangnya kembali, uangnya tidak keluar —
 * yang dia pegang adalah hak beli senilai dana bersih yang dulu kita terima.
 *
 * Saldonya WAJIB hidup di kolam `customer_overpayments`, karena di situlah pembayaran faktur
 * membacanya. Kredit yang hanya tercatat di jurnal tidak akan pernah bisa dipakai.
 */
class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    private function akun(string $code, string $name, string $type): Account
    {
        return Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
    }

    private function siapkan(): array
    {
        $this->akun('2106', 'Kelebihan Bayar Customer', 'liability');
        $lawan = $this->akun('6105', 'Beban Kerugian Retur', 'expense');
        $cust  = Customer::create(['code' => 'CUST-TKR', 'name' => 'Bu Rina', 'is_active' => true]);

        return [$cust, $lawan];
    }

    private function buat(array $override = []): CustomerCredit
    {
        [$cust, $lawan] = $this->siapkan();

        return app(CustomerCreditService::class)->create(array_merge([
            'customer_id'        => $cust->id,
            'credit_date'        => now()->toDateString(),
            'direction'          => 'tambah',
            'amount'             => 84150,
            'counter_account_id' => $lawan->id,
            'reason'             => 'Tukar ukuran TBKD-13-t1-M3 → A5',
        ], $override));
    }

    public function test_kredit_menambah_saldo_yang_dibaca_pembayaran(): void
    {
        $credit = $this->buat();

        $this->assertSame('posted', $credit->status);
        $this->assertEqualsWithDelta(84150, app(CustomerCreditService::class)->balanceFor($credit->customer_id), 0.01);

        // Pembayaran faktur membaca kolam yang sama — kalau tidak, kreditnya tak terpakai.
        $this->assertEqualsWithDelta(
            84150,
            (float) app(CustomerCreditPaymentService::class)->getCustomerCreditBalance($credit->customer_id),
            0.01
        );
    }

    public function test_jurnalnya_dr_akun_lawan_cr_kelebihan_bayar(): void
    {
        $credit = $this->buat();

        $journal = Journal::with('lines')->find($credit->journal_id);
        $this->assertNotNull($journal);

        $byCode = $journal->lines->mapWithKeys(fn ($l) => [
            Account::find($l->account_id)->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit],
        ]);

        $this->assertEqualsWithDelta(84150, $byCode['6105']['d'], 0.01);
        $this->assertEqualsWithDelta(84150, $byCode['2106']['c'], 0.01);
    }

    public function test_kurangi_saldo_melebihi_yang_ada_ditolak(): void
    {
        [$cust, $lawan] = $this->siapkan();

        $this->expectException(DomainException::class);
        app(CustomerCreditService::class)->create([
            'customer_id'        => $cust->id,
            'credit_date'        => now()->toDateString(),
            'direction'          => 'kurang',
            'amount'             => 10000,
            'counter_account_id' => $lawan->id,
        ]);
    }

    public function test_void_mengembalikan_saldo_dan_membatalkan_jurnal(): void
    {
        $credit = $this->buat();

        app(CustomerCreditService::class)->void($credit);

        $this->assertSame('void', $credit->fresh()->status);
        $this->assertEqualsWithDelta(0, app(CustomerCreditService::class)->balanceFor($credit->customer_id), 0.01);
        $this->assertSame('void', Journal::find($credit->journal_id)->status);
    }

    public function test_halaman_dan_simpan_lewat_form_berjalan(): void
    {
        [$cust, $lawan] = $this->siapkan();
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->get(route('sales.kredit.index'))->assertOk();
        $this->get(route('sales.kredit.create'))->assertOk();

        // Nominal datang berformat Indonesia dari input rupiah — "84.150", bukan 84.15.
        $this->post(route('sales.kredit.store'), [
            'customer_id'        => $cust->id,
            'credit_date'        => now()->toDateString(),
            'direction'          => 'tambah',
            'amount'             => '84.150',
            'counter_account_id' => $lawan->id,
            'reason'             => 'Tukar ukuran',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(84150, app(CustomerCreditService::class)->balanceFor($cust->id), 0.01);
    }

    public function test_kredit_yang_sudah_terpakai_tidak_bisa_di_void(): void
    {
        $credit = $this->buat();

        // Pelanggan memakai saldonya untuk membayar sesuatu.
        CustomerOverpayment::create([
            'customer_id' => $credit->customer_id,
            'amount'      => -84150,
            'reference'   => 'INV-DUMMY',
            'note'        => 'Dipakai bayar faktur',
        ]);

        $this->assertFalse($credit->fresh()->canBeVoided());
        $this->assertStringContainsString('sudah terpakai', (string) $credit->fresh()->voidBlocker());

        $this->expectException(DomainException::class);
        app(CustomerCreditService::class)->void($credit->fresh());
    }
}
