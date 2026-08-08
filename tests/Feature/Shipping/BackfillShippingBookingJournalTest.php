<?php

namespace Tests\Feature\Shipping;

use App\Core\Accounting\Account;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Models\Customer;
use App\Models\FreightSetting;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resi Jubelio yang terbit saat akun "Saldo Jubelio Shipment" belum diset tetap memotong
 * Coins di sisi Jubelio, tapi di buku tidak ada jejaknya — jurnalnya gagal diam-diam
 * (hanya flash kuning saat booking). Command ini menambalnya belakangan.
 */
class BackfillShippingBookingJournalTest extends TestCase
{
    use RefreshDatabase;

    private function siapkanAkun(): void
    {
        Account::firstOrCreate(['code' => '1203'], [
            'name' => 'Titipan Ongkir', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => 1,
        ]);
    }

    private function delivery(string $provider = 'jubelio_shipment', float $cost = 52500, string $status = 'posted'): SalesDelivery
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);

        $so = SalesOrder::create([
            'order_number' => 'SO-BF-' . uniqid(), 'customer_id' => $cust->id, 'warehouse_id' => $wh->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 200000, 'paid_amount' => 200000,
            'shipping_provider' => $provider,
        ]);

        return SalesDelivery::create([
            'delivery_number'   => 'SJ-BF-' . uniqid(),
            'sales_order_id'    => $so->id,
            'warehouse_id'      => $wh->id,
            'delivery_method'   => 'kurir',
            'shipping_provider' => $provider,
            'shipping_status'   => 'booked',
            'shipping_cost'     => $cost,
            'delivery_date'     => now()->toDateString(),
            'status'            => $status,
        ]);
    }

    private function saldo(int $accountId): float
    {
        return (float) JournalLine::where('account_id', $accountId)
            ->whereHas('journal', fn ($q) => $q->where('status', '!=', 'void'))
            ->sum(\DB::raw('debit - credit'));
    }

    /** Migrasi 2026_08_08_100000 harus menyiapkan akunnya, bukan menunggu operator. */
    public function test_migrasi_membuat_akun_jubelio_dan_memasangnya_ke_pengaturan_ongkir(): void
    {
        $saldo = Account::where('code', '1116')->first();
        $fee   = Account::where('code', '5292')->first();

        $this->assertNotNull($saldo, 'Akun 1116 Saldo Jubelio Shipment harus dibuat migrasi.');
        $this->assertNotNull($fee, 'Akun 5292 Beban Layanan Jubelio Shipment harus dibuat migrasi.');
        $this->assertSame(1, (int) $saldo->is_cash_account, 'Akun Coins harus kas-like agar bisa di-top-up lewat Transfer Antar Bank.');
        $this->assertNull($fee->account_category, 'Jangan berkategori bank_admin_fee — itu dikunci ke 5103.');

        $setting = FreightSetting::singleton();
        $this->assertSame($saldo->id, $setting->jubelio_saldo_account_id);
        $this->assertSame($fee->id, $setting->jubelio_fee_account_id);
    }

    public function test_backfill_menjurnal_sj_yang_belum_pernah_terjurnal(): void
    {
        $this->siapkanAkun();
        $sj = $this->delivery('jubelio_shipment', 52500);

        $this->artisan('shipping:backfill-booking-journal')->assertExitCode(0);

        $titipan = Account::where('code', '1203')->value('id');
        $coins   = FreightSetting::singleton()->jubelio_saldo_account_id;

        $this->assertEqualsWithDelta(52500, $this->saldo($titipan), 0.01);
        $this->assertEqualsWithDelta(-52500, $this->saldo($coins), 0.01, 'Saldo Coins harus terkredit sebesar ongkir aktual.');
        $this->assertDatabaseHas('journal_lines', [
            'description' => 'Potong Saldo Jubelio Shipment ' . $sj->delivery_number,
        ]);
    }

    public function test_dry_run_tidak_menulis_apa_apa(): void
    {
        $this->siapkanAkun();
        $this->delivery('jubelio_shipment', 30000);

        $this->artisan('shipping:backfill-booking-journal', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Journal::where('reference_type', 'shipping_booking')->count());
    }

    /** Dijalankan dua kali tidak boleh memotong saldo dua kali. */
    public function test_idempoten(): void
    {
        $this->siapkanAkun();
        $this->delivery('jubelio_shipment', 45000);

        $this->artisan('shipping:backfill-booking-journal')->assertExitCode(0);
        $this->artisan('shipping:backfill-booking-journal')->assertExitCode(0);

        $coins = FreightSetting::singleton()->jubelio_saldo_account_id;
        $this->assertEqualsWithDelta(-45000, $this->saldo($coins), 0.01);
        $this->assertSame(1, Journal::where('reference_type', 'shipping_booking')->count());
    }

    /** SJ yang sudah di-void tidak punya utang jurnal — bookingnya juga tak pernah tercatat. */
    public function test_sj_void_dilewati(): void
    {
        $this->siapkanAkun();
        $this->delivery('jubelio_shipment', 25000, 'void');

        $this->artisan('shipping:backfill-booking-journal')->assertExitCode(0);

        $this->assertSame(0, Journal::where('reference_type', 'shipping_booking')->count());
    }

    /** Kurir manual tidak punya deposit — jangan ikut tersapu. */
    public function test_kurir_manual_tidak_ikut_dijurnal(): void
    {
        $this->siapkanAkun();
        $this->delivery('manual', 20000);

        $this->artisan('shipping:backfill-booking-journal', ['--provider' => 'all'])->assertExitCode(0);

        $this->assertSame(0, Journal::where('reference_type', 'shipping_booking')->count());
    }

    public function test_provider_tak_dikenal_ditolak(): void
    {
        $this->artisan('shipping:backfill-booking-journal', ['--provider' => 'jne'])->assertExitCode(1);
    }
}
