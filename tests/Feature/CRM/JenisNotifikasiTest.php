<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\CrmSetting;
use App\Models\User;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\CRM\Support\JenisNotifikasi;
use App\Modules\CRM\Support\TemplateResmi;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Katalog & saklar jenis notifikasi di layar Notifikasi Pesanan.
 *
 * Yang dijaga: contoh teks di layar tidak boleh melenceng dari kalimat yang
 * benar-benar dikirim, dan jenis yang dimatikan tidak boleh hilang tanpa
 * jejak — kalau hilang, "kenapa pelanggan ini tidak dapat kabar?" tak akan
 * pernah terjawab.
 */
class JenisNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        config(['crm.dry_run' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function salesOrder(): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Budi', 'phone' => '628998844666',
            'wa_opt_in' => true, 'is_active' => true,
        ]);

        return SalesOrder::create([
            'order_number' => 'SO-JN-' . uniqid(),
            'customer_id'  => $cust->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Uji Jenis'])->id,
            'order_date'   => now(),
            'status'       => 'draft',
            'grand_total'  => 500000,
        ]);
    }

    /* ------------------------------------------------------------------ katalog */

    public function test_katalog_memuat_semua_jenis_beserta_teks_jadinya(): void
    {
        $katalog = JenisNotifikasi::katalog();

        // Empat berbayar (template Meta, termasuk "Jatuh Tempo") + dua yang
        // khusus WAHA ("Tagihan Pembayaran" & "Stok Sudah Ada").
        $this->assertCount(6, $katalog);

        foreach ($katalog as $j) {
            $this->assertNotEmpty($j['teks'], "Teks contoh {$j['event']} kosong.");
            $this->assertStringNotContainsString('{{', $j['teks'], 'Variabel template harus sudah terisi.');
        }
    }

    /**
     * Contoh di layar WAJIB dirangkai dari sumber yang sama dengan pengiriman.
     * Kalau ditulis ulang, keduanya melenceng pelan-pelan tanpa ada yang sadar,
     * dan layar ini justru jadi sumber keyakinan yang keliru.
     */
    public function test_teks_contoh_berasal_dari_template_yang_sama_dengan_pengiriman(): void
    {
        $katalog = collect(JenisNotifikasi::katalog())->keyBy('event');
        $contoh  = JenisNotifikasi::DAFTAR[CrmOutboxMessage::EVENT_PEMBAYARAN]['contoh'];

        $this->assertSame(
            TemplateResmi::render('pembayaran_diterima', $contoh),
            $katalog[CrmOutboxMessage::EVENT_PEMBAYARAN]['teks']
        );
    }

    public function test_hitungan_per_jenis_ikut_terbawa(): void
    {
        $katalog = collect(JenisNotifikasi::katalog([
            CrmOutboxMessage::EVENT_DIKIRIM => ['terkirim' => 7, 'gagal' => 2],
        ]))->keyBy('event');

        $this->assertSame(7, $katalog[CrmOutboxMessage::EVENT_DIKIRIM]['hitungan']['terkirim']);
        $this->assertSame(2, $katalog[CrmOutboxMessage::EVENT_DIKIRIM]['hitungan']['gagal']);
        $this->assertSame(0, $katalog[CrmOutboxMessage::EVENT_DIKIRIM]['hitungan']['menunggu']);
    }

    /* ------------------------------------------------------------------- saklar */

    /** Belum pernah diatur = menyala. Bawaan yang mendiamkan notifikasi berbahaya. */
    public function test_bawaan_semua_jenis_aktif(): void
    {
        config(['crm.notifikasi.aktif' => []]);

        $this->assertTrue(JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_PEMBAYARAN));
    }

    public function test_jenis_yang_dimatikan_dicatat_dilewati_bukan_hilang(): void
    {
        config(['crm.notifikasi.aktif' => [CrmOutboxMessage::EVENT_PEMBAYARAN => false]]);

        $baris = app(OrderNotificationService::class)
            ->antrekanPembayaranDiterima($this->salesOrder(), 100000);

        $this->assertNotNull($baris, 'Barisnya harus tetap ada sebagai jejak.');
        $this->assertSame(CrmOutboxMessage::STATUS_DILEWATI, $baris->status);
        $this->assertStringContainsString('dimatikan', (string) $baris->reason);
        $this->assertNull($baris->scheduled_at, 'Yang dilewati tak boleh punya jadwal kirim.');
    }

    public function test_jenis_lain_tidak_ikut_mati(): void
    {
        config(['crm.notifikasi.aktif' => [CrmOutboxMessage::EVENT_PEMBAYARAN => false]]);

        $baris = app(OrderNotificationService::class)->antrekanSiapDiambil($this->salesOrder());

        $this->assertSame(CrmOutboxMessage::STATUS_MENUNGGU, $baris->status);
    }

    /* -------------------------------------------------------------------- layar */

    public function test_layar_menampilkan_katalog_beserta_bunyi_kalimatnya(): void
    {
        $this->actingAs($this->admin())
            ->get(route('crm.notifikasi.index'))
            ->assertOk()
            ->assertSee('Jenis Notifikasi')
            ->assertSee('Pembayaran Diterima')
            ->assertSee('sudah kami terima', false);
    }

    /** Saklar harus benar-benar sampai ke config yang dibaca pengantre. */
    public function test_saklar_tersimpan_dan_terbaca_modul(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.notifikasi.jenis'), ['aktif' => ['dikirim' => '1']])
            ->assertSessionHas('success', fn ($p) => str_contains($p, 'DIMATIKAN'));

        CrmRuntimeConfig::apply(true);

        $this->assertTrue(JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_DIKIRIM));
        $this->assertFalse(JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_PEMBAYARAN));
        $this->assertFalse(JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_SIAP_AMBIL));

        $this->assertSame(
            [
                'pembayaran_diterima' => false,
                'siap_diambil'        => false,
                'dikirim'             => true,
                'jatuh_tempo'         => false,
                'tagihan_pembayaran'  => false,
                'stok_tersedia'       => false,
            ],
            CrmSetting::for('apicoid')->config['notifikasi_aktif']
        );
    }
}
