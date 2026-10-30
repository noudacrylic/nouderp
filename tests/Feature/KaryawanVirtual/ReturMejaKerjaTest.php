<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Period\AccountingPeriod;
use App\DTO\SalesReturnDTO;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Admin\ReturIsiHasil;
use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeReturService;
use Carbon\Carbon;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kartu retur di Meja Layanan (skill admin.retur) mengikuti status retur Shopee
 * (periksa → banding/Pending → hasil), dan skill admin.retur-isi-hasil mengisi draft dari
 * rincian dana Shopee tanpa memposting.
 */
class ReturMejaKerjaTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://openplatform.sandbox.test-stable.shopee.sg';
    private const SN   = '2610CCCC3333';

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
        $hold   = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit');
        $wallet = $akun('1104', 'Saldo Penjualan Shopee', 'asset', 'debit');
        $admin  = $akun('5101', 'Beban Admin Shopee', 'expense', 'debit');
        $akun('4004', 'Retur Penjualan', 'revenue', 'debit');
        $akun('6105', 'Beban Kerugian Retur', 'expense', 'debit');
        $akun('5001', 'Harga Pokok Penjualan', 'expense', 'debit');

        $cust = Customer::create(['code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
        MarketplaceConfig::create([
            'customer_id' => $cust, 'admin_fee_percent' => 10, 'admin_fee_fixed' => 0, 'tax_percent' => 0, 'is_active' => true,
            'account_receivable_hold_id' => $hold, 'account_fee_id' => $admin, 'account_wallet_id' => $wallet,
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

    private function shopeeRetur(string $status, array $isi = []): ShopeeReturn
    {
        return ShopeeReturn::updateOrCreate(['env' => 'test', 'shop_id' => 555, 'return_sn' => 'R-' . self::SN], array_merge([
            'order_sn' => self::SN, 'status' => $status, 'reason' => 'BROKEN_PRODUCTS', 'refund_amount' => 100000,
            'needs_logistics' => true, 'seller_due_at' => now()->addDay(), 'dibuat_shopee_at' => now()->subDays(2),
        ], $isi));
    }

    /** Nyalakan skill kartu retur; $draftBaru = draft dianggap lahir SETELAH skill dinyalakan. */
    private function nyalakan(bool $draftBaru = true): void
    {
        app(KaryawanVirtualService::class)->ubahMode('admin.retur', Skill::OTOMATIS, null);
        if ($draftBaru) {
            DB::table('sales_returns')->where('id', $this->retur->id)->update(['created_at' => now()->addMinute()]);
        }
    }

    private function kartu(string $fase): ?Task
    {
        return Task::where('kunci_sumber', 'retur:' . $this->retur->id . ':' . $fase)->first();
    }

    private function kartuTerbuka()
    {
        return Task::where('skill', 'retur')->whereIn('status', ['open', 'in_progress', 'pending'])->get();
    }

    private function sinkron(): void
    {
        app(MejaKerjaService::class)->sinkron();
    }

    public function test_retur_shopee_berjalan_jadi_kartu_periksa_dengan_tenggat_shopee(): void
    {
        Http::fake();
        $batas = now()->addHours(10)->startOfMinute();
        $this->shopeeRetur('PROCESSING', ['seller_due_at' => $batas]);
        $this->nyalakan(draftBaru: false);

        $this->sinkron();

        $t = $this->kartu('periksa');
        $this->assertNotNull($t);
        $this->assertSame('open', $t->status);
        $this->assertSame('layanan', $t->peran);
        $this->assertStringContainsString('Periksa barang retur', $t->title);
        $this->assertEquals($batas, $t->tenggat_at);
        Http::assertNothingSent();

        $aksi = app(MejaKerjaService::class)->skill('retur')->aksi($t);
        $this->assertSame(route('sales.returns.edit', $this->retur->id), $aksi[0]['url']);
        $this->assertSame('R-' . self::SN, collect($aksi)->firstWhere('label', 'Buka di Seller Centre')['salin']);
        $this->assertTrue(MejaKerjaService::bolehSelesaiManual(app(MejaKerjaService::class)->skill('retur'), $t), 'admin menandai selesai setelah menjawab di Seller Centre');
    }

    public function test_tarik_retur_menyimpan_batas_respons_dalam_jam_lokal(): void
    {
        $batas = Carbon::parse('2026-10-31 18:55:00', 'Asia/Jakarta');
        Http::fake([
            self::HOST . '/api/v2/returns/get_return_list*' => fn ($req) => Http::response(['response' => [
                'return' => str_contains($req->url(), 'page_no=0') && !ShopeeReturn::exists() ? [[
                    'return_sn' => 'R-' . self::SN, 'order_sn' => self::SN, 'status' => 'PROCESSING', 'reason' => 'CHANGE_MIND',
                    'refund_amount' => 100000, 'needs_logistics' => true, 'return_seller_due_date' => $batas->timestamp,
                    'negotiation_status' => '', 'seller_proof_status' => 'NOT_NEEDED',
                ]] : [],
                'more' => false,
            ], 'error' => '']),
        ]);

        app(ShopeeReturService::class)->tarik(ShopeeShop::first(), 10);

        $x = ShopeeReturn::firstOrFail();
        $this->assertSame('2026-10-31 18:55:00', DB::table('shopee_returns')->value('seller_due_at'));
        $this->assertTrue($x->needs_logistics);
        $this->assertNull($x->negotiation_status);
        $this->assertFalse($x->pernah_disengketakan);
    }

    public function test_banding_menutup_kartu_periksa_lalu_kartu_baru_saat_hasil_keluar(): void
    {
        Http::fake();
        $this->shopeeRetur('PROCESSING');
        $this->nyalakan();
        $this->sinkron();
        $this->assertSame('open', $this->kartu('periksa')->status);

        // Banding tercatat di Shopee → kartu periksa selesai, tidak ada kartu menunggu/pending.
        $this->shopeeRetur('SELLER_DISPUTE', ['pernah_disengketakan' => true]);
        $this->sinkron();
        $this->assertSame('done', $this->kartu('periksa')->fresh()->status);
        $this->assertCount(0, $this->kartuTerbuka());

        // Hasil banding keluar → kartu BARU untuk menyelesaikan.
        $this->shopeeRetur('ACCEPTED');
        $this->sinkron();
        $t = $this->kartu('hasil');
        $this->assertNotNull($t);
        $this->assertSame('open', $t->status);
        $this->assertStringStartsWith('Banding sudah selesai', $t->title);
        $this->assertFalse(MejaKerjaService::bolehSelesaiManual(app(MejaKerjaService::class)->skill('retur'), $t), 'selesai saat retur diposting');
    }

    public function test_periksa_yang_ditandai_selesai_admin_tidak_dibuat_ulang(): void
    {
        Http::fake();
        $this->shopeeRetur('PROCESSING');
        $this->nyalakan();
        $this->sinkron();
        $t = $this->kartu('periksa');
        app(MejaKerjaService::class)->ubahStatus($t, 'done', null, 'Ditandai selesai');

        $this->sinkron();

        $this->assertSame(1, Task::where('skill', 'retur')->count());
        $this->assertCount(0, $this->kartuTerbuka());
    }

    public function test_hasil_final_draft_diisi_lalu_kartu_selesai_saat_diposting(): void
    {
        $this->shopeeRetur('ACCEPTED');
        Http::fake([
            self::HOST . '/api/v2/payment/get_escrow_detail*' => Http::response(['response' => ['order_income' => [
                'escrow_amount' => 0, 'escrow_amount_after_adjustment' => 0, 'seller_return_refund' => -100000,
            ]], 'error' => '']),
            self::HOST . '/api/v2/order/get_order_detail*' => Http::response(['response' => ['order_list' => [
                ['order_sn' => self::SN, 'order_status' => 'COMPLETED'],
            ]], 'error' => '']),
        ]);
        $this->nyalakan();
        $this->sinkron();
        $this->assertStringStartsWith('Retur selesai di Shopee', $this->kartu('hasil')->title);

        $jalan = app(KaryawanVirtualService::class)->jalankan(ReturIsiHasil::KEY, KaryawanVirtualService::PEMICU_JADWAL);

        $this->assertSame('selesai', $jalan['hasil'][0]['status'] ?? null, json_encode($jalan['hasil']));
        $r = $this->retur->fresh();
        $this->assertSame('draft', $r->status, 'hanya diisi, tidak diposting');
        $this->assertSame('K1', $r->return_case, 'barang dikirim balik → K1');
        $this->assertNotNull($r->journal_reversal);
        $this->assertStringContainsString('Manajer Admin', $r->notes);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'get_return_list'));

        $this->sinkron();
        $this->assertStringContainsString('Draft sudah diisi', $this->kartu('hasil')->fresh()->description);

        DB::table('sales_returns')->where('id', $r->id)->update(['status' => 'posted']);
        $this->sinkron();
        $this->assertSame('done', $this->kartu('hasil')->fresh()->status);
    }

    public function test_isi_hasil_melewati_retur_yang_masih_berjalan(): void
    {
        $this->shopeeRetur('PROCESSING');
        Http::fake();

        $jalan = app(KaryawanVirtualService::class)->jalankan(ReturIsiHasil::KEY, KaryawanVirtualService::PEMICU_JADWAL);

        $this->assertSame([], $jalan['hasil']);
        $this->assertNull($this->retur->fresh()->return_case);
        Http::assertNothingSent();
    }

    public function test_draft_lama_non_shopee_langsung_pending(): void
    {
        Http::fake();
        DB::table('sales_orders')->where('order_number', 'SP-' . self::SN)->update(['order_number' => 'SO-2026-0001']);
        $this->nyalakan(draftBaru: false);

        $this->sinkron();

        $t = $this->kartu('manual');
        $this->assertSame('pending', $t->status);
        $this->assertStringContainsString('Retur lama', $t->pending_alasan);
        $this->assertStringStartsWith('Selesaikan retur', $t->title);
        $this->assertNull(collect(app(MejaKerjaService::class)->skill('retur')->aksi($t))->firstWhere('label', 'Buka di Seller Centre'));
    }
}
