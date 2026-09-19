<?php

namespace Tests\Feature\Sales;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\Journal;
use App\Core\Period\AccountingPeriod;
use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\MarketplaceEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settlement marketplace untuk faktur GAYA BARU.
 *
 * Faktur gaya baru terbit saat PENGIRIMAN mengikuti Sales Order persis: nilainya KOTOR dan
 * biaya adminnya belum dibebankan. Begitu pesanan selesai, engine melepas Saldo Ditahan ke
 * Wallet sekaligus membebankan biaya admin yang baru diketahui:
 *
 *     Dr Wallet (sisa hold − fee) + Dr Beban Admin (fee) / Cr Saldo Ditahan (sisa hold)
 *
 * Yang paling mudah salah: retur yang diposting LEBIH DULU sudah mengkredit akun hold sendiri.
 * Kalau settlement tetap mengkredit DP penuh, akun hold dikredit dua kali dan jadi MINUS.
 */
class MarketplaceEngineSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $holdId;
    private int $walletId;
    private int $feeId;
    private int $advanceId;
    private int $productId;
    private SalesOrder $so;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date'   => now()->endOfMonth()->toDateString(),
                'status'     => 'open',
            ]
        );

        $akun = fn (string $code, string $name, string $type, string $normal, string $cat) =>
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type,
                'normal_balance' => $normal, 'account_category' => $cat, 'is_active' => true,
            ])->id;

        $this->advanceId = $akun('2105', 'Uang Muka Customer', 'liability', 'credit', 'payable');
        $this->holdId    = $akun('1202', 'Saldo Ditahan Shopee', 'asset', 'debit', 'cash');
        $this->walletId  = $akun('1111', 'Wallet Shopee', 'asset', 'debit', 'cash');
        $this->feeId     = $akun('6101', 'Beban Admin Marketplace', 'expense', 'debit', 'expense');

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        MarketplaceConfig::create([
            'customer_id'                => $this->customerId,
            'admin_fee_percent'          => 0,
            'admin_fee_fixed'            => 0,
            'is_active'                  => true,
            'account_receivable_hold_id' => $this->holdId,
            'account_fee_id'             => $this->feeId,
            'account_wallet_id'          => $this->walletId,
        ]);

        $warehouseId = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;

        $this->productId = Product::firstOrCreate(['sku' => 'MP-TEST'], [
            'name' => 'Produk Uji', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 1000, 'is_active' => true, 'is_sellable' => true,
        ])->id;

        // Pesanan kotor 77.999 — DP masuk hold sebesar itu (aturan DP kotor).
        $this->so = SalesOrder::create([
            'order_number' => 'SO-MP-1',
            'customer_id'  => $this->customerId,
            'warehouse_id' => $warehouseId,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'subtotal'     => 77999,
            'grand_total'  => 77999,
            'paid_amount'  => 77999,
        ]);

        SalesAdvance::create([
            'advance_number'  => 'ADV-1',
            'sales_order_id'  => $this->so->id,
            'customer_id'     => $this->customerId,
            'bank_account_id' => $this->holdId,
            'advance_date'    => now()->toDateString(),
            'amount'          => 77999,
            'status'          => 'posted',
        ]);
    }

    private function faktur(bool $gayaBaru = true): SalesInvoice
    {
        return SalesInvoice::create([
            'invoice_number'    => 'INV-MP-1',
            'sales_order_id'    => $this->so->id,
            'customer_id'       => $this->customerId,
            'warehouse_id'      => $this->so->warehouse_id,
            'invoice_date'      => now()->toDateString(),
            'subtotal'          => 77999,
            'grand_total'       => $gayaBaru ? 77999 : 66219,
            'marketplace_fee'   => $gayaBaru ? 0 : 11780,
            'fee_at_settlement' => $gayaBaru,
            'status'            => 'posted',
        ]);
    }

    /** @return array<int,array{debit:float,credit:float}> saldo per akun dari jurnal settlement */
    private function jurnalSettlement(SalesInvoice $inv): array
    {
        $j = Journal::where('reference_type', 'sales_invoice_settlement')
            ->where('reference_id', $inv->id)->where('status', '!=', 'void')->firstOrFail();

        $out = [];
        foreach (DB::table('journal_lines')->where('journal_id', $j->id)->get() as $l) {
            $out[(int) $l->account_id] = [
                'debit'  => round((float) ($out[(int) $l->account_id]['debit'] ?? 0) + (float) $l->debit, 2),
                'credit' => round((float) ($out[(int) $l->account_id]['credit'] ?? 0) + (float) $l->credit, 2),
            ];
        }

        return $out;
    }

    public function test_faktur_gaya_baru_membebankan_fee_dan_melepas_hold_penuh(): void
    {
        $inv = $this->faktur();

        app(MarketplaceEngineService::class)->handle($inv, 11780);

        $l = $this->jurnalSettlement($inv);
        $this->assertEqualsWithDelta(66219.0, $l[$this->walletId]['debit'], 0.01, 'wallet = kotor − fee');
        $this->assertEqualsWithDelta(11780.0, $l[$this->feeId]['debit'], 0.01, 'biaya admin dibebankan di sini');
        $this->assertEqualsWithDelta(77999.0, $l[$this->holdId]['credit'], 0.01, 'hold dilepas penuh');
        $this->assertArrayNotHasKey($this->advanceId, $l, 'tak ada sisa yang perlu direklas ke uang muka');

        $this->assertEqualsWithDelta(11780.0, (float) $inv->fresh()->marketplace_fee, 0.01,
            'fee dicatat di faktur sebagai "sudah dibebankan" untuk rekonsiliasi');
        $this->assertEqualsWithDelta(77999.0, (float) $inv->fresh()->grand_total, 0.01,
            'grand_total TIDAK diturunkan — tetap kotor');
    }

    public function test_retur_lebih_dulu_tidak_membuat_hold_dikredit_dua_kali(): void
    {
        $inv = $this->faktur();

        // Retur 30.999 yang sudah diposting & mengkredit akun hold.
        $r = SalesReturn::create([
            'return_number'  => 'SR-1',
            'customer_id'    => $this->customerId,
            'sales_order_id' => $this->so->id,
            'return_date'    => now()->toDateString(),
            'grand_total'    => 30999,
            'status'         => 'posted',
            'stage'          => 'selesai',
        ]);
        SalesReturnItem::create([
            'sales_return_id' => $r->id, 'reference_item_id' => 0,
            'product_id' => $this->productId, 'qty' => 1, 'unit_price' => 30999,
            'subtotal' => 30999, 'condition' => 'good',
        ]);
        $this->jurnalRetur($r->id, 30999);

        app(MarketplaceEngineService::class)->handle($inv, 6000);

        $l = $this->jurnalSettlement($inv);
        $sisa = 77999 - 30999; // 47.000

        $this->assertEqualsWithDelta($sisa, $l[$this->holdId]['credit'], 0.01,
            'hold hanya dilepas sebesar SISA, bukan DP penuh');
        $this->assertEqualsWithDelta($sisa - 6000, $l[$this->walletId]['debit'], 0.01);
        $this->assertEqualsWithDelta(6000.0, $l[$this->feeId]['debit'], 0.01);

        // Inti pengujiannya: retur + settlement bersama-sama mengkredit akun hold PERSIS
        // sebesar DP (30.999 + 47.000 = 77.999) — tidak lebih. Jadi kalau jurnal DP-nya ikut
        // ada (Dr 77.999), akun hold berakhir nol. Sebelum perbaikan, settlement mengkredit DP
        // penuh sehingga totalnya 108.998 dan hold jadi minus 30.999.
        $kreditHold = (float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')->where('jl.account_id', $this->holdId)
            ->selectRaw('SUM(jl.credit) - SUM(jl.debit) v')->value('v');
        $this->assertEqualsWithDelta(77999.0, $kreditHold, 0.01,
            'retur + settlement mengkredit hold persis sebesar DP, tidak dobel');
    }

    public function test_faktur_gaya_lama_tidak_membebankan_fee_lagi(): void
    {
        $inv = $this->faktur(false);

        app(MarketplaceEngineService::class)->handle($inv, 11780);

        $l = $this->jurnalSettlement($inv);
        $this->assertArrayNotHasKey($this->feeId, $l, 'fee faktur lama sudah dibebankan di jurnal faktur');
        $this->assertEqualsWithDelta(66219.0, $l[$this->walletId]['debit'], 0.01);
        $this->assertEqualsWithDelta(77999.0, $l[$this->holdId]['credit'], 0.01);
        $this->assertEqualsWithDelta(11780.0, $l[$this->advanceId]['debit'], 0.01,
            'selisih kotor↔bersih direklas ke uang muka, seperti sebelumnya');
    }

    /** Jurnal retur tiruan: Dr Uang Muka / Cr Saldo Ditahan, seperti SalesReturnService. */
    private function jurnalRetur(int $returnId, float $amount): void
    {
        app(\App\Core\Journal\JournalPostingService::class)->post(new \App\DTO\JournalEntryDTO(
            date: now()->toDateString(),
            reference_type: 'sales_return',
            reference_id: $returnId,
            description: 'Retur uji',
            lines: [
                new \App\DTO\JournalLineDTO(account_id: $this->advanceId, debit: $amount, credit: 0, description: 'Retur'),
                new \App\DTO\JournalLineDTO(account_id: $this->holdId, debit: 0, credit: $amount, description: 'Retur'),
            ]
        ));
    }
}
