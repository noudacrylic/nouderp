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
 * Tautan pembayaran yang DP-nya sudah lunas harus tetap bisa dipakai untuk pelunasan.
 *
 * Alamat tautan itu sudah beredar di WhatsApp pembeli dan akan dibuka lagi saat
 * melunasi. Karena satu baris transaksi = satu pembayaran, pelunasan lahir sebagai
 * transaksi baru — tapi TOKEN-nya dipindahkan, bukan diterbitkan ulang.
 */
class PaymentLinkPelunasanTest extends TestCase
{
    use RefreshDatabase;

    private function salesOrder(int $grandTotal, int $paid): SalesOrder
    {
        $cust = Customer::create(['code' => 'CUST-DP', 'name' => 'Pembeli DP', 'is_active' => true]);

        return SalesOrder::create([
            'order_number' => 'SO-DP-1',
            'customer_id' => $cust->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => 'confirmed',
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
        ]);
    }

    private function paidLink(SalesOrder $so, int $amount): MidtransTransaction
    {
        return MidtransTransaction::forceCreate([
            'order_id'       => 'NOUD-SODP-LAMA',
            'sales_order_id' => $so->id,
            'customer_id'    => $so->customer_id,
            'source'         => 'link',
            'channel'        => 'qris',
            'status'         => 'settlement',
            'gross_amount'   => $amount,
            'base_amount'    => $amount,
            'min_dp_amount'  => $amount,
            'link_token'     => 'token-dp-lama',
            'expired_at'     => now()->addDay(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_token_berpindah_ke_transaksi_pelunasan(): void
    {
        $so = $this->salesOrder(750000, 375000);
        $dp = $this->paidLink($so, 375000);

        $next = app(PaymentLinkService::class)->continueForRemaining($dp);

        $this->assertNotNull($next);
        $this->assertSame('token-dp-lama', $next->link_token, 'Tautan pembeli tidak boleh berubah.');
        $this->assertSame('pending', $next->status);
        $this->assertSame($so->id, $next->sales_order_id);
        // Sisa tagihan dibayar penuh — DP adalah tanda jadi yang berlaku sekali.
        $this->assertSame(375000, (int) round($next->min_dp_amount));

        // Transaksi DP-nya sendiri tidak diutak-atik, hanya kehilangan tokennya supaya
        // tidak ada dua baris memperebutkan alamat yang sama.
        $dp->refresh();
        $this->assertNull($dp->link_token);
        $this->assertSame('settlement', $dp->status);
        $this->assertSame(375000, (int) round($dp->gross_amount));
    }

    public function test_tautan_ditemukan_lewat_token_yang_sama(): void
    {
        $so = $this->salesOrder(750000, 375000);
        $dp = $this->paidLink($so, 375000);

        $service = app(PaymentLinkService::class);
        $service->continueForRemaining($dp);

        $ketemu = $service->findByToken('token-dp-lama');
        $this->assertNotNull($ketemu);
        $this->assertSame('pending', $ketemu->status, 'Membuka tautan lama harus mendarat di transaksi pelunasan.');
    }

    public function test_pesanan_yang_sudah_lunas_tidak_diperpanjang(): void
    {
        $so = $this->salesOrder(750000, 750000);
        $dp = $this->paidLink($so, 750000);

        $this->assertNull(
            app(PaymentLinkService::class)->continueForRemaining($dp),
            'Tidak ada sisa tagihan — jangan buat transaksi baru.'
        );
        $this->assertSame('token-dp-lama', $dp->fresh()->link_token);
    }

    public function test_klik_ganda_tidak_membuat_transaksi_kembar(): void
    {
        $so = $this->salesOrder(750000, 375000);
        $dp = $this->paidLink($so, 375000);

        $service = app(PaymentLinkService::class);
        $pertama = $service->continueForRemaining($dp);
        $kedua = $service->continueForRemaining($dp->fresh());

        $this->assertNotNull($pertama);
        // Panggilan kedua melihat transaksi DP sudah kehilangan token → berhenti.
        $this->assertNull($kedua);
        $this->assertSame(2, MidtransTransaction::where('sales_order_id', $so->id)->count());
    }
}
