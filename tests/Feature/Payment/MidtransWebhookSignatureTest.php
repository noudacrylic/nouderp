<?php

namespace Tests\Feature\Payment;

use App\Models\MidtransSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifikasi tanda tangan webhook Midtrans.
 *
 * Yang dijaga: kuncinya diambil dari Pengaturan → Midtrans, BUKAN dari .env. Dulu
 * middleware ini membaca .env sementara halaman Pengaturan menyimpan ke database,
 * sehingga mengganti kunci lewat UI (persis yang terjadi saat pindah ke produksi)
 * membuat semua notifikasi ditolak 403 tanpa gejala apa pun di layar — pelanggan
 * membayar, ERP tidak pernah mencatatnya.
 */
class MidtransWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER  = 'NOUD-TEST-0001';
    private const AMOUNT = '440000.00';

    public function test_kunci_diambil_dari_pengaturan_bukan_env(): void
    {
        // Yang berbeda inilah intinya: .env sengaja dibiarkan memakai kunci lama.
        config(['services.midtrans.server_key' => 'KUNCI-LAMA-DI-ENV']);
        MidtransSetting::singleton()->update(['server_key' => 'KUNCI-BARU-DI-PENGATURAN']);

        // Lolos middleware kalau BUKAN 403. Controller-nya lalu menolak karena order_id
        // tidak dikenal — pesan itu justru buktinya tanda tangan sudah diterima.
        $this->postJson(route('midtrans.notify'), $this->payload('KUNCI-BARU-DI-PENGATURAN'))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['error' => 'MidtransTransaction tidak ditemukan: ' . self::ORDER]);
    }

    public function test_tanda_tangan_dengan_kunci_lama_ditolak(): void
    {
        config(['services.midtrans.server_key' => 'KUNCI-LAMA-DI-ENV']);
        MidtransSetting::singleton()->update(['server_key' => 'KUNCI-BARU-DI-PENGATURAN']);

        $this->postJson(route('midtrans.notify'), $this->payload('KUNCI-LAMA-DI-ENV'))
            ->assertStatus(403);
    }

    public function test_env_dipakai_bila_pengaturan_masih_kosong(): void
    {
        // Instalasi baru yang belum pernah membuka halaman Pengaturan.
        config(['services.midtrans.server_key' => 'KUNCI-DARI-ENV']);
        MidtransSetting::singleton()->update(['server_key' => null]);

        $this->postJson(route('midtrans.notify'), $this->payload('KUNCI-DARI-ENV'))
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_payload_tanpa_tanda_tangan_ditolak(): void
    {
        $this->postJson(route('midtrans.notify'), ['order_id' => self::ORDER])
            ->assertStatus(400);
    }

    /** @return array<string,string> payload webhook dengan tanda tangan dari $key */
    private function payload(string $key): array
    {
        $statusCode = '200';

        return [
            'order_id'           => self::ORDER,
            'status_code'        => $statusCode,
            'gross_amount'       => self::AMOUNT,
            'transaction_status' => 'settlement',
            'signature_key'      => hash('sha512', self::ORDER . $statusCode . self::AMOUNT . $key),
        ];
    }
}
