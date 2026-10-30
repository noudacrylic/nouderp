<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Admin\ReturTidakJadi;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Skill Manajer Admin "Selesaikan retur tidak jadi (Shopee)": draft retur yang returnya
 * dibatalkan pembeli & pesanannya selesai → ditutup, faktur dicairkan dengan angka Shopee.
 */
class ReturTidakJadiTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://openplatform.sandbox.test-stable.shopee.sg';
    private const SN   = '2610AAAA1111';

    private int $walletId;
    private int $adminId;
    private SalesInvoice $inv;
    private SalesReturn $retur;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
        ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);

        $b = now();
        AccountingPeriod::firstOrCreate(
            ['year' => (int) $b->year, 'month' => (int) $b->month],
            ['start_date' => $b->copy()->startOfMonth()->toDateString(), 'end_date' => $b->copy()->endOfMonth()->toDateString(), 'status' => 'open']
        );
        $akun = fn (string $code, string $name, string $type, string $normal) =>
            Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => true])->id;
        $akun('1120', 'Piutang Usaha', 'asset', 'debit');
        $akun('2105', 'Uang Muka Customer', 'liability', 'credit');
        $akun('2106', 'Kelebihan Bayar Customer', 'liability', 'credit');
        $hold           = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit');
        $this->walletId = $akun('1104', 'Saldo Penjualan Shopee', 'asset', 'debit');
        $this->adminId  = $akun('5101', 'Beban Admin Shopee', 'expense', 'debit');
        $akun('4004', 'Retur Penjualan', 'revenue', 'debit');

        $cust = Customer::create(['code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
        MarketplaceConfig::create([
            'customer_id' => $cust, 'admin_fee_percent' => 10, 'admin_fee_fixed' => 0, 'tax_percent' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $hold, 'account_fee_id' => $this->adminId, 'account_wallet_id' => $this->walletId,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $produk = Product::firstOrCreate(['sku' => 'TBKD-A6'], [
            'name' => 'Tempat Brosur A6', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 50000, 'is_active' => true, 'is_sellable' => true,
        ]);
        $so = SalesOrder::create([
            'order_number' => 'SP-' . self::SN, 'customer_id' => $cust, 'warehouse_id' => $wh,
            'order_date' => now()->subDays(10)->toDateString(), 'status' => 'confirmed',
            'subtotal' => 100000, 'grand_total' => 100000, 'paid_amount' => 100000,
        ]);
        SalesAdvance::create([
            'advance_number' => 'ADV-1', 'sales_order_id' => $so->id, 'customer_id' => $cust, 'bank_account_id' => $hold,
            'advance_date' => now()->toDateString(), 'amount' => 100000, 'status' => 'posted',
        ]);
        $this->inv = SalesInvoice::create([
            'invoice_number' => 'SP-' . self::SN, 'sales_order_id' => $so->id, 'customer_id' => $cust, 'warehouse_id' => $wh,
            'invoice_date' => now()->toDateString(), 'subtotal' => 100000, 'grand_total' => 100000,
            'advance_applied' => 0, 'marketplace_processed' => false, 'fee_at_settlement' => true, 'status' => 'posted',
        ]);
        $itemId = DB::table('sales_invoice_items')->insertGetId([
            'sales_invoice_id' => $this->inv->id, 'product_id' => $produk->id, 'description' => 'Tempat Brosur A6', 'item_type' => 'product',
            'qty' => 2, 'unit_price' => 50000, 'subtotal' => 100000, 'cogs_unit' => 20000, 'cogs_total' => 40000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->retur = app(SalesReturnService::class)->saveDraft(new SalesReturnDTO(
            customer_id: $cust, items: [['invoice_item_id' => $itemId, 'qty' => 2, 'condition' => 'good']],
            date: now()->toDateString(), invoice_id: $this->inv->id,
        ));
    }

    private function shopee(string $statusRetur, string $statusPesanan): void
    {
        Http::fake([
            self::HOST . '/api/v2/returns/get_return_list*' => fn ($req) => Http::response(['response' => [
                'return' => str_contains($req->url(), 'page_no=0') ? [[
                    'return_sn' => 'R1', 'order_sn' => self::SN, 'status' => $statusRetur, 'reason' => 'BROKEN_PRODUCTS', 'refund_amount' => 100000,
                ]] : [],
                'more' => false,
            ], 'error' => '']),
            self::HOST . '/api/v2/payment/get_escrow_detail*' => Http::response(['response' => ['order_income' => [
                'escrow_amount' => 87000, 'escrow_amount_after_adjustment' => 87000, 'seller_return_refund' => 0,
            ]], 'error' => '']),
            self::HOST . '/api/v2/order/get_order_detail*' => Http::response(['response' => ['order_list' => [
                ['order_sn' => self::SN, 'order_status' => $statusPesanan],
            ]], 'error' => '']),
        ]);
    }

    private function jalan(): array
    {
        return app(KaryawanVirtualService::class)->jalankan(ReturTidakJadi::KEY, KaryawanVirtualService::PEMICU_JADWAL);
    }

    private function saldo(int $akun): float
    {
        return round((float) DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')->where('jl.account_id', $akun)
            ->selectRaw('COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) v')->value('v'), 2);
    }

    public function test_retur_batal_dan_pesanan_selesai_ditutup_dengan_potongan_shopee(): void
    {
        $this->shopee('CANCELLED', 'COMPLETED');

        $jalan = $this->jalan();

        $this->assertSame(Skill::OTOMATIS, $jalan['mode']);
        $r = $this->retur->fresh();
        $this->assertSame('void', $r->status);
        $this->assertSame('batal', $r->stage);
        $this->assertStringContainsString('Manajer Admin', $r->notes, 'riwayat menyebut siapa yang mengerjakan');
        $this->assertSame(0, Journal::where('reference_type', 'sales_return')->where('reference_id', $r->id)->count());

        // Dana cair persis angka Shopee (bukan taksiran admin 10%).
        $this->assertEqualsWithDelta(87000, $this->saldo($this->walletId), 0.01);
        $this->assertEqualsWithDelta(13000, $this->saldo($this->adminId), 0.01);
        $inv = $this->inv->fresh();
        $this->assertTrue((bool) $inv->marketplace_processed);
        $this->assertEqualsWithDelta(0, $inv->remaining_amount, 0.01);

        // Tercatat di Jurnal Kerja Manajer Admin, bertautan ke dokumen retur.
        $j = KaryawanVirtualJurnal::where('skill', ReturTidakJadi::KEY)->latest('id')->first();
        $this->assertNotNull($j);
        $this->assertStringContainsString($r->return_number, json_encode($j->rincian, JSON_UNESCAPED_SLASHES));

        // Jalan berikutnya tak menemukan apa-apa lagi.
        $this->assertSame([], $this->jalan()['hasil']);
    }

    public function test_pesanan_belum_selesai_dibiarkan(): void
    {
        $this->shopee('CANCELLED', 'SHIPPED');

        $this->assertSame([], $this->jalan()['hasil']);
        $this->assertSame('draft', $this->retur->fresh()->status);
        $this->assertSame(0.0, $this->saldo($this->walletId));
    }

    public function test_retur_yang_diterima_bukan_urusan_skill_ini(): void
    {
        $this->shopee('ACCEPTED', 'COMPLETED');

        $this->assertSame([], $this->jalan()['hasil']);
        $this->assertSame('draft', $this->retur->fresh()->status);
    }

    public function test_mode_bayangan_hanya_mencatat(): void
    {
        $this->shopee('CANCELLED', 'COMPLETED');
        app(KaryawanVirtualService::class)->ubahMode(ReturTidakJadi::KEY, Skill::BAYANGAN, null);

        $hasil = $this->jalan()['hasil'];

        $this->assertCount(1, $hasil);
        $this->assertSame('akan', $hasil[0]['status']);
        $this->assertSame('draft', $this->retur->fresh()->status);
        $this->assertSame(0.0, $this->saldo($this->walletId));
    }

    public function test_tidak_ikut_sinkron_meja_kerja_tiap_dua_menit(): void
    {
        Http::fake();
        app(MejaKerjaService::class)->sinkron();

        Http::assertNothingSent();
        $this->assertSame('draft', $this->retur->fresh()->status);
    }
}
