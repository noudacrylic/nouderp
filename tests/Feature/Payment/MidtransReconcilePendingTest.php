<?php

namespace Tests\Feature\Payment;

use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Modules\Payment\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Jaring pengaman kalau notifikasi webhook tidak sampai.
 *
 * Yang dijaga: transaksi yang ternyata sudah dibayar ikut tersusul, transaksi yang
 * gagal dicek tidak menghentikan sisanya, dan mode uji coba benar-benar tidak menyentuh
 * data. Panggilan ke Midtrans dipalsukan — tes tidak boleh bergantung jaringan.
 */
class MidtransReconcilePendingTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        MidtransSetting::singleton()->update(['server_key' => 'KUNCI-UJI', 'is_production' => false]);

        $this->customerId = DB::table('customers')->insertGetId([
            'code'       => 'CUST-UJI',
            'name'       => 'Pelanggan Uji',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_transaksi_yang_ternyata_kedaluwarsa_ikut_diperbarui(): void
    {
        $this->transaction('NOUD-A');

        Http::fake(['api.sandbox.midtrans.com/*' => Http::response([
            'order_id'           => 'NOUD-A',
            'transaction_status' => 'expire',
            'gross_amount'       => '440000.00',
            'status_code'        => '202',
        ], 200)]);

        $r = app(MidtransService::class)->reconcilePending();

        $this->assertSame(1, $r['checked']);
        $this->assertSame([['order_id' => 'NOUD-A', 'from' => 'pending', 'to' => 'expire']], $r['updated']);
        $this->assertSame('expire', MidtransTransaction::where('order_id', 'NOUD-A')->value('status'));
    }

    public function test_yang_masih_pending_tidak_dihitung_berubah(): void
    {
        $this->transaction('NOUD-B');

        Http::fake(['api.sandbox.midtrans.com/*' => Http::response([
            'order_id'           => 'NOUD-B',
            'transaction_status' => 'pending',
            'gross_amount'       => '440000.00',
            'status_code'        => '201',
        ], 200)]);

        $r = app(MidtransService::class)->reconcilePending();

        $this->assertSame(1, $r['unchanged']);
        $this->assertSame([], $r['updated']);
    }

    public function test_transaksi_tidak_dikenal_midtrans_dilaporkan_terpisah(): void
    {
        $this->transaction('NOUD-C');

        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404'], 404)]);

        $r = app(MidtransService::class)->reconcilePending();

        $this->assertSame(1, $r['not_found']);
        $this->assertSame([], $r['failed'], '404 bukan kegagalan — transaksinya memang tidak pernah dibuat.');
    }

    public function test_satu_gagal_tidak_menghentikan_sisanya(): void
    {
        $this->transaction('NOUD-D', now()->subDay());
        $this->transaction('NOUD-E', now());

        Http::fake(function ($request) {
            return str_contains($request->url(), 'NOUD-D')
                ? Http::response('gateway down', 502)
                : Http::response([
                    'order_id'           => 'NOUD-E',
                    'transaction_status' => 'expire',
                    'gross_amount'       => '440000.00',
                    'status_code'        => '202',
                ], 200);
        });

        $r = app(MidtransService::class)->reconcilePending();

        $this->assertSame(2, $r['checked']);
        $this->assertCount(1, $r['failed']);
        $this->assertSame('NOUD-D', $r['failed'][0]['order_id']);
        // Yang penting: NOUD-E tetap terproses walau NOUD-D gagal duluan.
        $this->assertSame('expire', MidtransTransaction::where('order_id', 'NOUD-E')->value('status'));
    }

    public function test_mode_uji_coba_tidak_mengubah_data(): void
    {
        $this->transaction('NOUD-F');

        Http::fake(['api.sandbox.midtrans.com/*' => Http::response([
            'order_id'           => 'NOUD-F',
            'transaction_status' => 'expire',
            'gross_amount'       => '440000.00',
            'status_code'        => '202',
        ], 200)]);

        $r = app(MidtransService::class)->reconcilePending(200, true);

        $this->assertCount(1, $r['updated'], 'Perubahan tetap dilaporkan supaya bisa ditinjau dulu.');
        $this->assertSame('pending', MidtransTransaction::where('order_id', 'NOUD-F')->value('status'));
    }

    private function transaction(string $orderId, $createdAt = null): MidtransTransaction
    {
        // Kolom wajib diisi seadanya — yang diuji cuma alur status, bukan isi transaksinya.
        return MidtransTransaction::forceCreate([
            'order_id'     => $orderId,
            'customer_id'  => $this->customerId,
            'source'       => 'link',
            'channel'      => 'qris',
            'status'       => 'pending',
            'gross_amount' => 440000,
            'base_amount'  => 440000,
            'expired_at'   => now()->addDay(),
            'created_at'   => $createdAt ?? now(),
            'updated_at'   => $createdAt ?? now(),
        ]);
    }
}
