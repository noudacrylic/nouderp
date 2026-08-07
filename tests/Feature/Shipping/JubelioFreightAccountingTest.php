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
use App\Modules\Shipping\Services\ShippingAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jurnal titipan ongkir dulu ditulis saat Biteship satu-satunya agregator, dan gerbangnya
 * memaku nama itu (`if ($provider !== 'biteship') return;`). Sejak Jubelio Shipment jadi
 * satu-satunya yang dipakai, resi yang terbit lewat Jubelio LOLOS tanpa jurnal sama sekali —
 * saldo depositnya tak pernah berkurang di buku.
 *
 * Tes ini mengunci perbaikannya: tiap agregator berbayar-di-muka punya akun saldo sendiri
 * dan sama-sama dijurnal.
 */
class JubelioFreightAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function akun(string $code, string $name, string $type = 'asset'): Account
    {
        return Account::firstOrCreate(['code' => $code], [
            'name' => $name, 'type' => $type, 'normal_balance' => 'debit', 'is_active' => 1,
        ]);
    }

    private function siapkanAkun(): array
    {
        $this->akun('1203', 'Titipan Ongkir');
        $saldoJubelio  = $this->akun('1116', 'Saldo Jubelio Shipment');
        $saldoBiteship = $this->akun('1115', 'Saldo Biteship');
        $fee           = $this->akun('5292', 'Beban Layanan Jubelio', 'expense');
        $gain          = $this->akun('4210', 'Pendapatan Ongkir', 'revenue');
        $loss          = $this->akun('5291', 'Diskon Ongkir', 'expense');

        FreightSetting::singleton()->update([
            'gain_account_id'           => $gain->id,
            'loss_account_id'           => $loss->id,
            'biteship_saldo_account_id' => $saldoBiteship->id,
            'jubelio_saldo_account_id'  => $saldoJubelio->id,
            'jubelio_fee_account_id'    => $fee->id,
        ]);

        return compact('saldoJubelio', 'saldoBiteship', 'fee');
    }

    private function delivery(string $provider, float $cost = 52500): SalesDelivery
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);

        $so = SalesOrder::create([
            'order_number' => 'SO-JF-' . uniqid(), 'customer_id' => $cust->id, 'warehouse_id' => $wh->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 200000, 'paid_amount' => 200000,
            'shipping_provider' => $provider,
        ]);

        return SalesDelivery::create([
            'delivery_number'   => 'SJ-JF-' . uniqid(),
            'sales_order_id'    => $so->id,
            'warehouse_id'      => $wh->id,
            'delivery_method'   => 'kurir',
            'shipping_provider' => $provider,
            'shipping_status'   => 'booked',
            'shipping_cost'     => $cost,
            'delivery_date'     => now()->toDateString(),
            'status'            => 'posted',
        ]);
    }

    private function saldo(int $accountId): float
    {
        return (float) JournalLine::where('account_id', $accountId)
            ->whereHas('journal', fn ($q) => $q->where('status', '!=', 'void'))
            ->sum(\DB::raw('debit - credit'));
    }

    public function test_booking_jubelio_memotong_saldo_jubelio(): void
    {
        $akun = $this->siapkanAkun();
        $sj   = $this->delivery('jubelio_shipment', 52500);

        app(ShippingAccountingService::class)->postBooking($sj);

        $titipan = Account::where('code', '1203')->value('id');

        $this->assertEqualsWithDelta(52500, $this->saldo($titipan), 0.01, 'Titipan ongkir harus terdebet');
        $this->assertEqualsWithDelta(-52500, $this->saldo($akun['saldoJubelio']->id), 0.01, 'Saldo Jubelio harus terkredit');
        $this->assertEqualsWithDelta(0, $this->saldo($akun['saldoBiteship']->id), 0.01, 'Saldo Biteship tidak boleh tersentuh');
    }

    public function test_keterangan_jurnal_menyebut_provider_yang_benar(): void
    {
        $this->siapkanAkun();
        $sj = $this->delivery('jubelio_shipment');

        app(ShippingAccountingService::class)->postBooking($sj);

        $this->assertDatabaseHas('journal_lines', [
            'description' => 'Potong Saldo Jubelio Shipment ' . $sj->delivery_number,
        ]);
    }

    public function test_kurir_manual_tetap_tidak_dijurnal(): void
    {
        $this->siapkanAkun();
        $sj = $this->delivery('manual');

        app(ShippingAccountingService::class)->postBooking($sj);

        $this->assertSame(0, Journal::where('reference_type', 'shipping_booking')->count(),
            'Kurir non-agregator tidak punya deposit — jangan dijurnal.');
    }

    public function test_void_sj_mengembalikan_saldo_jubelio(): void
    {
        $akun = $this->siapkanAkun();
        $sj   = $this->delivery('jubelio_shipment', 40000);
        $svc  = app(ShippingAccountingService::class);

        $svc->postBooking($sj);
        $svc->reverseBooking($sj);

        $this->assertEqualsWithDelta(0, $this->saldo($akun['saldoJubelio']->id), 0.01,
            'Setelah dibalik, saldo Jubelio harus kembali seperti semula.');

        // Dibalik dua kali (mis. void diproses ulang) tidak boleh menggelembungkan saldo.
        $svc->reverseBooking($sj);
        $this->assertEqualsWithDelta(0, $this->saldo($akun['saldoJubelio']->id), 0.01);
    }

    /** SJ yang pernah di-void lalu dibooking ulang tetap harus dijurnal. */
    public function test_booking_ulang_setelah_void_dijurnal_lagi(): void
    {
        $akun = $this->siapkanAkun();
        $sj   = $this->delivery('jubelio_shipment', 30000);
        $svc  = app(ShippingAccountingService::class);

        $svc->postBooking($sj);
        $svc->reverseBooking($sj);
        $svc->postBooking($sj);

        $this->assertEqualsWithDelta(-30000, $this->saldo($akun['saldoJubelio']->id), 0.01,
            'Booking ulang harus memotong saldo lagi, bukan diam karena dianggap sudah pernah.');
    }

    public function test_rekonsiliasi_saldo_jubelio_mencatat_selisih_ke_beban_jubelio(): void
    {
        $akun = $this->siapkanAkun();
        $sj   = $this->delivery('jubelio_shipment', 50000);
        $svc  = app(ShippingAccountingService::class);
        $svc->postBooking($sj);

        // Buku −50.000; saldo asli di dashboard −52.000 → ada Rp2.000 biaya yang belum tercatat.
        $res = $svc->reconcileSaldo(-52000, null, 'jubelio_shipment');

        $this->assertTrue($res['posted']);
        $this->assertEqualsWithDelta(2000, $res['diff'], 0.01);
        $this->assertEqualsWithDelta(2000, $this->saldo($akun['fee']->id), 0.01, 'Selisih masuk Beban Layanan Jubelio');
    }

    public function test_akun_saldo_jubelio_belum_diset_memberi_pesan_yang_menyebut_providernya(): void
    {
        $this->siapkanAkun();
        FreightSetting::singleton()->update(['jubelio_saldo_account_id' => null]);

        $sj = $this->delivery('jubelio_shipment');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Akun 'Saldo Jubelio Shipment' belum diset");

        app(ShippingAccountingService::class)->postBooking($sj);
    }
}
