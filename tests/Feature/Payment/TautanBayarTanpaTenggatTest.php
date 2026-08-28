<?php

namespace Tests\Feature\Payment;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MidtransTransaction;
use App\Modules\Payment\Services\PaymentLinkService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alamat /pay/{token} tidak punya masa berlaku.
 *
 * Yang berumur pendek adalah KODE BAYAR-nya (QRIS/VA) yang terbit saat pembeli
 * menekan Bayar — batas itu dikirim ke Midtrans lewat payload `expiry`. Tautannya
 * sendiri tetap dipakai pembeli untuk memantau pesanan dan mengunduh nota, jadi
 * tidak boleh ikut mati.
 */
class TautanBayarTanpaTenggatTest extends TestCase
{
    use RefreshDatabase;

    private function salesOrder(int $grandTotal = 500000, int $paid = 0): SalesOrder
    {
        $cust = Customer::create(['code' => 'CUST-LINK', 'name' => 'Pembeli Link', 'is_active' => true]);

        return SalesOrder::create([
            'order_number' => 'SO-LINK-1',
            'customer_id' => $cust->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => 'confirmed',
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
        ]);
    }

    public function test_tautan_baru_dibuat_tanpa_tenggat(): void
    {
        $trx = app(PaymentLinkService::class)->getOrCreateForSalesOrder($this->salesOrder(), null, 250000);

        $this->assertNull($trx->expired_at, 'Tautan bayar tidak boleh punya tenggat sendiri.');
        $this->assertFalse($trx->isExpired());
    }

    public function test_tautan_lama_tanpa_tenggat_dipakai_ulang_bukan_dibuat_baru(): void
    {
        $so = $this->salesOrder();
        $svc = app(PaymentLinkService::class);

        $first = $svc->getOrCreateForSalesOrder($so, null, 250000);
        $second = $svc->getOrCreateForSalesOrder($so, null, 250000);

        $this->assertSame($first->id, $second->id, 'Klik kedua tidak boleh menerbitkan token baru.');
        $this->assertSame(1, MidtransTransaction::where('sales_order_id', $so->id)->count());
    }

    public function test_pesan_whatsapp_tidak_menyebut_tenggat_bila_memang_tidak_ada(): void
    {
        $so = $this->salesOrder();
        $svc = app(PaymentLinkService::class);
        $trx = $svc->getOrCreateForSalesOrder($so, null, 250000);

        $pesan = $svc->waTextSo($trx, 'Pembeli Link', $so->order_number, 500000);

        $this->assertStringNotContainsString('sampai', $pesan);
        $this->assertStringNotContainsString('hari', $pesan);
        $this->assertStringContainsString($svc->publicUrl($trx), $pesan);
    }

    public function test_kode_bayar_hangus_tidak_mematikan_tautannya(): void
    {
        $so = $this->salesOrder();
        $svc = app(PaymentLinkService::class);
        $trx = $svc->getOrCreateForSalesOrder($so, null, 250000);

        // Pembeli menekan Bayar, lalu QRIS-nya lewat batas tanpa dibayar.
        $trx->update([
            'status' => 'expire',
            'expired_at' => now()->subDay(),
            'snap_token' => 'token-lama',
            'snap_redirect_url' => 'https://app.midtrans.com/snap/v4/redirection/token-lama',
        ]);

        $hidup = $svc->reviveExpiredCharge($trx->fresh());

        $this->assertSame('pending', $hidup->status);
        $this->assertNull($hidup->expired_at);
        $this->assertNull($hidup->snap_token, 'Kode bayar lama harus dibuang agar tidak dipakai ulang.');
        $this->assertFalse($hidup->isExpired());
    }

    public function test_tautan_yang_dokumennya_dibatalkan_tetap_mati(): void
    {
        $so = $this->salesOrder();
        $svc = app(PaymentLinkService::class);
        $trx = $svc->getOrCreateForSalesOrder($so, null, 250000);

        // Pembatalan dokumen memakai status 'cancel' — bukan 'expire' — jadi tidak
        // boleh ikut dihidupkan lagi oleh pemulihan kode bayar.
        $trx->update(['status' => 'cancel', 'expired_at' => now()]);

        $sesudah = $svc->reviveExpiredCharge($trx->fresh());

        $this->assertSame('cancel', $sesudah->status);
    }

    public function test_halaman_bayar_terbuka_meski_tanpa_tenggat(): void
    {
        $so = $this->salesOrder();
        $trx = app(PaymentLinkService::class)->getOrCreateForSalesOrder($so, null, 250000);

        $this->get('/pay/' . $trx->link_token)
            ->assertOk()
            ->assertSee('Simpan tautan ini', false);
    }
}
