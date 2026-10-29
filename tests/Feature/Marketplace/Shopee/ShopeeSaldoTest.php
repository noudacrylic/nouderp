<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Modules\Finance\Models\BankTransfer;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Services\BankTransferService;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Models\ShopeeWalletTransaction as Mutasi;
use App\Modules\Marketplace\Shopee\Services\ShopeeSaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 1b — mutasi saldo Shopee lewat API: penarikan dana jadi draft Transfer Bank tanpa
 * tujuan, isi ulang iklan jadi draft Pengeluaran 5009 + PPN 5010, tanpa dobel dengan dokumen
 * yang sudah mencatatnya.
 */
class ShopeeSaldoTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeShop $shop;
    private array $akun = [];
    private array $transaksi = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-uji', 'env' => 'test']]);
        $this->shop = ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);

        foreach ([['1104', 'Saldo Penjualan Shopee', true], ['1102', 'Bank BCA', true], ['5009', 'Iklan', false], ['5010', 'PPN pembelian', false]] as [$kode, $nama, $kas]) {
            $this->akun[$kode] = Account::firstOrCreate(['code' => $kode], [
                'name' => $nama, 'type' => $kas ? 'asset' : 'expense', 'normal_balance' => 'debit',
                'account_category' => $kas ? 'cash' : 'expense', 'is_active' => true,
            ])->id;
            Account::whereKey($this->akun[$kode])->update(['is_cash_account' => $kas]);
        }

        $t = now()->subHours(3)->timestamp;
        $this->transaksi = [
            ['transaction_id' => 11, 'transaction_type' => 'WITHDRAWAL_COMPLETED', 'amount' => 0, 'current_balance' => 0, 'create_time' => $t + 1, 'description' => 'Penarikan Dana', 'order_sn' => '', 'status' => 'COMPLETED'],
            ['transaction_id' => 10, 'transaction_type' => 'WITHDRAWAL_CREATED', 'amount' => -6000000, 'current_balance' => 0, 'create_time' => $t, 'description' => 'Penarikan Dana', 'order_sn' => '', 'status' => 'COMPLETED'],
            ['transaction_id' => 9, 'transaction_type' => 'SPM_DEDUCT', 'amount' => -3330000, 'current_balance' => 6000000, 'create_time' => $t - 60, 'description' => 'Isi Ulang Saldo Iklan/Koin Penjual', 'order_sn' => '', 'status' => 'COMPLETED'],
            ['transaction_id' => 8, 'transaction_type' => 'ESCROW_VERIFIED_ADD', 'amount' => 150000, 'current_balance' => 9330000, 'create_time' => $t - 120, 'description' => 'Penghasilan dari Pesanan', 'order_sn' => '261029ABCDEFGH', 'status' => 'COMPLETED'],
        ];

        Http::fake(fn () => Http::response(['response' => ['transaction_list' => $this->transaksi, 'more' => false]]));
    }

    public function test_penarikan_jadi_draft_transfer_tanpa_tujuan_dan_iklan_jadi_draft_pengeluaran(): void
    {
        $h = app(ShopeeSaldoService::class)->tarik($this->shop, now()->subDay());

        $this->assertSame(['baru' => 3, 'draft_transfer' => 1, 'draft_iklan' => 1, 'sudah_tercatat' => 0], $h);
        $this->assertSame(['pesanan', 'iklan', 'penarikan'], Mutasi::orderBy('waktu')->pluck('kategori')->all());

        $bt = BankTransfer::sole();
        $this->assertSame('draft', $bt->status);
        $this->assertSame($this->akun['1104'], (int) $bt->from_account_id);
        $this->assertNull($bt->to_account_id);
        $this->assertEquals(6000000, (float) $bt->amount);

        $cd = CashDisbursement::with('lines')->sole();
        $this->assertSame('draft', $cd->status);
        $this->assertSame($this->akun['1104'], (int) $cd->cash_account_id);
        $this->assertEquals([3000000, 330000], $cd->lines->sortBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->values()->all());
        $this->assertEquals([$this->akun['5009'], $this->akun['5010']], $cd->lines->sortBy('id')->pluck('account_id')->map(fn ($v) => (int) $v)->values()->all());

        // Jalan ulang: tak ada baris maupun draft kembar.
        $h2 = app(ShopeeSaldoService::class)->tarik($this->shop, now()->subDay());
        $this->assertSame(['baru' => 0, 'draft_transfer' => 0, 'draft_iklan' => 0, 'sudah_tercatat' => 0], $h2);
        $this->assertSame(1, BankTransfer::count());
        $this->assertSame(1, CashDisbursement::count());
    }

    public function test_penarikan_yang_sudah_dijurnal_manual_tidak_dibuatkan_draft(): void
    {
        $periode = AccountingPeriod::firstOrCreate(['year' => (int) now()->year, 'month' => (int) now()->month], [
            'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open',
        ]);
        $j = Journal::create([
            'journal_number' => 'BT-J-LAMA', 'date' => now()->subDay()->toDateString(), 'period_id' => $periode->id,
            'reference_type' => 'bank_transfer', 'reference_id' => 999, 'reference_number' => 'BT-2026-0999',
            'description' => 'Transfer manual', 'status' => 'posted', 'posted_at' => now(),
        ]);
        JournalLine::create(['journal_id' => $j->id, 'account_id' => $this->akun['1102'], 'debit' => 6000000, 'credit' => 0]);
        JournalLine::create(['journal_id' => $j->id, 'account_id' => $this->akun['1104'], 'debit' => 0, 'credit' => 6000000]);

        $h = app(ShopeeSaldoService::class)->tarik($this->shop, now()->subDay());

        $this->assertSame(1, $h['sudah_tercatat']);
        $this->assertSame(0, BankTransfer::count());
        $penarikan = Mutasi::where('kategori', 'penarikan')->sole();
        $this->assertSame('journal', $penarikan->dokumen_type);
        $this->assertStringContainsString('BT-2026-0999', $penarikan->dokumen_catatan);
    }

    public function test_draft_transfer_tanpa_tujuan_tidak_bisa_diposting(): void
    {
        app(ShopeeSaldoService::class)->tarik($this->shop, now()->subDay());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Akun tujuan belum dipilih');
        app(BankTransferService::class)->post(BankTransfer::sole());
    }

    public function test_daftar_transfer_menandai_tujuan_yang_belum_dipilih(): void
    {
        app(ShopeeSaldoService::class)->tarik($this->shop, now()->subDay());
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('finance.cash-bank.transfers.index'))
            ->assertOk()->assertSee('pilih tujuan');
        $this->actingAs($admin)->get(route('finance.cash-bank.transfers.show', BankTransfer::sole()->id))
            ->assertOk()->assertSee('Belum dipilih');
    }
}
