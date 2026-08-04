<?php

namespace Tests\Feature\Payment;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dokumen yang dibatalkan tidak boleh lagi bisa dibayar.
 *
 * Tautan pembayaran sudah beredar di WhatsApp pembeli dan tidak bisa ditarik kembali.
 * Kalau SO/faktur-nya di-void atau dihapus, tautan itu HARUS mati — kalau tidak, uang
 * bisa masuk untuk pesanan yang sudah tidak ada, dan itu baru ketahuan saat rekonsiliasi.
 */
class PaymentLinkDokumenDibatalkanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MidtransSetting::singleton()->update(['server_key' => 'KUNCI-UJI', 'is_production' => false]);
        Http::fake(); // expire ke Midtrans tidak boleh benar-benar keluar saat uji
    }

    private function customerId(): int
    {
        return Customer::create(['code' => 'CUST-BATAL', 'name' => 'Pembeli Batal', 'is_active' => true])->id;
    }

    private function salesOrder(string $status = 'confirmed'): SalesOrder
    {
        return SalesOrder::create([
            'order_number' => 'SO-BATAL-1',
            'customer_id' => $this->customerId(),
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => $status,
            'grand_total' => 500000,
            'paid_amount' => 0,
        ]);
    }

    private function link(array $attrs): MidtransTransaction
    {
        return MidtransTransaction::forceCreate(array_merge([
            'order_id' => 'NOUD-SODP-BATAL',
            'source' => 'link',
            'channel' => 'snap_auto',
            'status' => 'pending',
            'gross_amount' => 0,
            'base_amount' => 0,
            'link_token' => 'token-batal',
            'expired_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    public function test_void_so_mematikan_tautan_dp(): void
    {
        $so = $this->salesOrder();
        $trx = $this->link(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);

        $so->update(['status' => 'void']);

        $trx->refresh();
        $this->assertSame('cancel', $trx->status, 'Transaksi menggantung harus ikut dibatalkan.');
        $this->assertTrue($trx->expired_at->isPast());

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('sudah dibatalkan');

        $this->postJson("/pay/{$trx->link_token}/snap", ['channel' => 'qris'])->assertStatus(410);
        $this->postJson("/pay/{$trx->link_token}/charge", ['channel' => 'qris'])->assertStatus(410);
    }

    public function test_hapus_so_draft_mematikan_tautan_dp(): void
    {
        $so = $this->salesOrder('draft');
        $trx = $this->link(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);

        $so->delete();

        $trx->refresh();
        $this->assertSame('cancel', $trx->status);

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('sudah dihapus');

        $this->postJson("/pay/{$trx->link_token}/snap", ['channel' => 'qris'])->assertStatus(410);
    }

    public function test_void_faktur_mematikan_tautan_pelunasan(): void
    {
        $so = $this->salesOrder();
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-BATAL-1',
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'warehouse_id' => $so->warehouse_id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'posted',
            'grand_total' => 500000,
        ]);

        $trx = $this->link([
            'order_id' => 'NOUD-LINK-BATAL',
            'sales_invoice_id' => $invoice->id,
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'gross_amount' => 500000,
            'base_amount' => 500000,
            'link_token' => 'token-batal-inv',
        ]);

        $invoice->update(['status' => 'void']);

        $trx->refresh();
        $this->assertSame('cancel', $trx->status);

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('sudah dibatalkan');

        $this->get("/pay/{$trx->link_token}/invoice.pdf")->assertNotFound();
    }

    public function test_tautan_lama_ikut_mati_walau_status_transaksinya_belum_sempat_diubah(): void
    {
        // Tautan yang terbit sebelum penonaktifan otomatis ada: statusnya masih pending,
        // tapi dokumennya sudah void. Halaman publik tetap wajib menolak.
        $so = $this->salesOrder();
        $trx = $this->link(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);

        SalesOrder::withoutEvents(fn () => $so->update(['status' => 'void']));

        $trx->refresh();
        $this->assertSame('pending', $trx->status, 'Prasyarat uji: status transaksi sengaja dibiarkan pending.');

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertSee('sudah dibatalkan');
    }

    public function test_dokumen_sehat_tetap_bisa_dibayar(): void
    {
        $so = $this->salesOrder();
        $trx = $this->link(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);

        $this->get("/pay/{$trx->link_token}")
            ->assertOk()
            ->assertDontSee('sudah dibatalkan');

        $trx->refresh();
        $this->assertSame('pending', $trx->status);
    }
}
