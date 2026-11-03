<?php

namespace Tests\Feature\Payment;

use App\Models\Customer;
use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use App\Models\User;
use App\Modules\Payment\Services\MidtransService;
use App\Modules\Sales\Models\SalesOrder;
use App\Core\Inventory\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pembeli ganti metode bayar: order_id baris tautan tertimpa. Kode lama harus dimatikan di
 * Midtrans, dan bila tetap dibayar owner harus tahu — bukan tenggelam sebagai baris log.
 */
class MidtransGantiMetodeTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'KUNCI-UJI';

    private MidtransTransaction $trx;
    private bool $chargeDitolak = false;

    protected function setUp(): void
    {
        parent::setUp();
        MidtransSetting::singleton()->update(['server_key' => self::KUNCI, 'is_production' => false]);
        config(['services.telegram.token' => 'TOKEN-UJI']);
        Http::fake([
            '*/v2/charge' => fn () => $this->chargeDitolak
                ? Http::response(['status_code' => '400', 'status_message' => 'ditolak'], 400)
                : Http::response(['status_code' => '201', 'transaction_status' => 'pending', 'va_numbers' => [['va_number' => '123']]]),
            '*/expire'    => Http::response(['status_code' => '200']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'telegram_chat_id' => '5001']);
        User::factory()->create(['role' => 'admin', 'is_active' => true, 'telegram_chat_id' => '5002']);

        $so = SalesOrder::create([
            'order_number' => 'SO-GANTI-1',
            'customer_id' => Customer::create(['code' => 'C-G', 'name' => 'Pembeli', 'is_active' => true])->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'status' => 'confirmed',
            'grand_total' => 500000,
            'paid_amount' => 0,
        ]);
        $this->trx = MidtransTransaction::forceCreate([
            'order_id' => 'NOUD-SODP-AWAL', 'source' => 'link', 'channel' => 'snap_auto', 'status' => 'pending',
            'gross_amount' => 0, 'base_amount' => 0, 'link_token' => 'token-ganti', 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id,
            'expired_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function charge(string $channel = 'va', ?string $bank = 'bca'): MidtransTransaction
    {
        return app(MidtransService::class)->chargeForLink($this->trx->fresh(), $channel, $bank, 100000);
    }

    private function expireTerkirim(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/expire'))
            ->map(fn ($p) => $p[0]->url())->values()->all();
    }

    private function notifikasi(string $orderId, string $status, array $tambahan = []): \Illuminate\Testing\TestResponse
    {
        $kode = '200';
        $nominal = '100000.00';

        return $this->postJson(route('midtrans.notify'), $tambahan + [
            'order_id' => $orderId, 'status_code' => $kode, 'gross_amount' => $nominal, 'transaction_status' => $status, 'payment_type' => 'qris',
            'signature_key' => hash('sha512', $orderId . $kode . $nominal . self::KUNCI),
        ]);
    }

    private function pesanTelegram(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    public function test_charge_pertama_tidak_mengirim_expire(): void
    {
        $trx = $this->charge();

        $this->assertSame([], $this->expireTerkirim(), 'Order awal belum dikenal Midtrans.');
        $this->assertNull($trx->order_id_lama);
    }

    public function test_ganti_metode_mematikan_kode_lama_dan_mencatatnya(): void
    {
        $lama = $this->charge()->order_id;

        $baru = $this->charge('qris', null);

        $this->assertNotSame($lama, $baru->order_id);
        $this->assertSame([$lama], $baru->order_id_lama);
        $this->assertCount(1, $this->expireTerkirim());
        $this->assertStringContainsString($lama, $this->expireTerkirim()[0]);
    }

    public function test_charge_baru_gagal_kode_lama_tetap_hidup(): void
    {
        $lama = $this->charge()->order_id;
        $this->chargeDitolak = true;

        try {
            $this->charge('qris', null);
            $this->fail('Seharusnya melempar galat.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($lama, $this->trx->fresh()->order_id);
        $this->assertSame([], $this->expireTerkirim());
    }

    public function test_notifikasi_kedaluwarsa_order_lama_tidak_jadi_galat(): void
    {
        $lama = $this->charge()->order_id;
        $this->charge('qris', null);
        Log::spy();

        $this->notifikasi($lama, 'expire')->assertOk()->assertJsonPath('ok', false);

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
        $this->assertSame([], $this->pesanTelegram());
        $this->assertSame('pending', $this->trx->fresh()->status, 'Transaksi aktif tidak tersentuh.');
    }

    public function test_order_lama_dibayar_owner_diberi_tahu_sekali_dengan_nomor_pesanan(): void
    {
        $lama = $this->charge()->order_id;
        $this->charge('qris', null);
        Log::spy();

        $this->notifikasi($lama, 'capture')->assertOk();
        $this->notifikasi($lama, 'settlement')->assertOk();

        $pesan = $this->pesanTelegram();
        $this->assertCount(1, $pesan, 'Hanya owner, dan hanya sekali.');
        $this->assertSame('5001', (string) $pesan[0]['chat_id']);
        $this->assertStringContainsString('BELUM tercatat', $pesan[0]['text']);
        $this->assertStringContainsString('SO-GANTI-1', $pesan[0]['text']);
        $this->assertStringContainsString('100.000', $pesan[0]['text']);
        Log::shouldHaveReceived('critical');

        // Tidak diposting otomatis: pesanan bisa saja sudah dibayar lewat kode baru.
        $this->assertNull($this->trx->fresh()->customer_payment_id);
    }

    public function test_order_yang_sama_sekali_tak_dikenal_tetap_memberi_alarm(): void
    {
        $this->notifikasi('NOUD-SODP-ASING', 'settlement')->assertOk();

        $pesan = $this->pesanTelegram();
        $this->assertCount(1, $pesan);
        $this->assertStringContainsString('tidak diketahui', $pesan[0]['text']);
    }

    public function test_order_aktif_tetap_diproses_seperti_biasa(): void
    {
        $aktif = $this->charge()->order_id;

        $this->notifikasi($aktif, 'expire')->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('expire', $this->trx->fresh()->status);
        $this->assertSame([], $this->pesanTelegram());
    }
}
