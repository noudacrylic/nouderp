<?php

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\MarketplaceConfig;
use App\Modules\Finance\Services\MarketplaceSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baris POTONGAN marketplace tanpa pesanan — premi asuransi pengiriman (−350 SPX / −505
 * Anteraja), "Biaya Lainnya", dsb.
 *
 * Dulu `resolveGross()` menyalin net jadi gross untuk baris tak cocok, sehingga
 * feeActual = gross − net = 0 dan baris ini TIDAK PERNAH menghasilkan jurnal. Akibatnya saldo
 * wallet ERP melar sebesar preminya tiap pesanan. Diperparah `splitMatchedAndPost()` yang
 * memindahkan semua baris tak cocok ke draf "pending", sehingga preminya mengendap selamanya.
 *
 * Sekarang: gross = 0 → feeActual = +350 → dibukukan sebagai biaya admin marketplace, dan
 * barisnya TIDAK ikut dipindah ke draf pending. Premi diperlakukan sebagai biaya admin karena
 * polanya memang sama — dibayar baik klaimnya nanti berhasil maupun gagal, dan dipotong
 * langsung dari dana cair.
 */
class MarketplaceSettlementChargeRowTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $customer = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true,
        ]);

        $this->config = MarketplaceConfig::create([
            'customer_id'       => $customer->id,
            'admin_fee_percent' => 0,
            'admin_fee_fixed'   => 0,
            'is_active'         => true,
        ]);
    }

    private function draft(array $rows)
    {
        return $this->app->make(MarketplaceSettlementService::class)
            ->createDraft($this->config, $rows, ['source_filename' => 'uji.xlsx']);
    }

    private function row(string $ref, float $net): array
    {
        return ['order_ref' => $ref, 'settlement_date' => now()->toDateString(), 'net_amount' => $net];
    }

    public function test_baris_minus_tanpa_pesanan_jadi_biaya_admin(): void
    {
        $ms = $this->draft([$this->row('260711D4TGFJYQ', -350)]);
        $line = $ms->lines->first();

        $this->assertEqualsWithDelta(0.0, (float) $line->gross_amount, 0.01,
            'gross berhenti menyalin net — itulah yang dulu menelan preminya');
        $this->assertEqualsWithDelta(350.0, (float) $line->fee_actual, 0.01,
            'potongan 350 jadi biaya admin yang dibukukan');
        $this->assertStringContainsString('premi asuransi', (string) $line->note);
    }

    public function test_total_settlement_ikut_menghitung_potongan(): void
    {
        $ms = $this->draft([$this->row('A', -350), $this->row('B', -505)]);

        $this->assertEqualsWithDelta(855.0, (float) $ms->total_fee_actual, 0.01);
        $this->assertEqualsWithDelta(-855.0, (float) $ms->total_net, 0.01);
    }

    public function test_baris_plus_tanpa_faktur_tetap_gross_sama_dengan_net(): void
    {
        // Pesanan pra-ERP / faktur belum dibuat: asumsi lama dipertahankan, fee 0 —
        // jangan sampai baris positif ikut terbaca sebagai potongan raksasa.
        $ms = $this->draft([$this->row('C', 77999)]);
        $line = $ms->lines->first();

        $this->assertEqualsWithDelta(77999.0, (float) $line->gross_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $line->fee_actual, 0.01);
    }
}
