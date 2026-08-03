<?php

namespace Tests\Feature\Payment;

use App\Models\MidtransSetting;
use App\Models\MidtransTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Metode bayar yang belum disetujui Midtrans tidak boleh bisa dipilih.
 *
 * Halaman /pay memang sudah menyaring tombolnya, tapi halaman yang sudah lama terbuka
 * bisa mengirim metode yang baru saja dimatikan. Tanpa penjagaan di server, pembeli
 * dilempar ke Snap lalu mentok tanpa penjelasan — tepat di detik ia hendak membayar.
 */
class MidtransActiveChannelsTest extends TestCase
{
    use RefreshDatabase;

    private MidtransTransaction $trx;

    protected function setUp(): void
    {
        parent::setUp();

        MidtransSetting::singleton()->update(['server_key' => 'KUNCI-UJI', 'is_production' => false]);

        $customerId = DB::table('customers')->insertGetId([
            'code'       => 'CUST-UJI',
            'name'       => 'Pelanggan Uji',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->trx = MidtransTransaction::forceCreate([
            'order_id'     => 'NOUD-CH',
            'customer_id'  => $customerId,
            'source'       => 'link',
            'channel'      => 'qris',
            'status'       => 'pending',
            'gross_amount' => 100000,
            'base_amount'  => 100000,
            'link_token'   => 'token-uji-channel',
            'expired_at'   => now()->addDay(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function test_bawaan_hanya_qris_va_dan_ewallet(): void
    {
        $this->assertSame(['qris', 'va', 'ewallet'], MidtransSetting::singleton()->activeChannels());
        $this->assertFalse(MidtransSetting::singleton()->channelActive('alfamart'));
    }

    public function test_metode_yang_belum_aktif_ditolak(): void
    {
        $res = $this->postJson("/pay/{$this->trx->link_token}/snap", ['channel' => 'alfamart']);

        $res->assertStatus(422);
        $this->assertStringContainsString('tidak tersedia', $res->json('error'));
    }

    public function test_metode_yang_diaktifkan_lolos_penjagaan(): void
    {
        MidtransSetting::singleton()->update(['active_channels' => ['qris', 'va', 'alfamart']]);

        $res = $this->postJson("/pay/{$this->trx->link_token}/snap", ['channel' => 'alfamart']);

        // Transaksi uji ini tidak menggantung ke SO/invoice mana pun, jadi permintaannya
        // tetap berhenti setelah penjagaan — yang diuji: BUKAN berhenti karena metodenya.
        $this->assertNotSame(
            422,
            $res->status(),
            'Metode yang sudah diaktifkan tidak boleh lagi ditolak sebagai metode tak tersedia.'
        );
    }

    public function test_daftar_kosong_jatuh_ke_bawaan_aman(): void
    {
        MidtransSetting::singleton()->update(['active_channels' => []]);

        // Kosong bukan berarti "semua boleh": tanpa jaring ini, satu simpanan kosong
        // membuka lagi metode yang belum disetujui.
        $this->assertSame(
            MidtransSetting::DEFAULT_ACTIVE_CHANNELS,
            MidtransSetting::singleton()->activeChannels()
        );
    }
}
