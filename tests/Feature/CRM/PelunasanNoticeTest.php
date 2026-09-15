<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Support\TemplateResmi;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\POS\Services\PelunasanNoticeService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengingat pelunasan untuk pesanan kirim di "Belum Lunas".
 *
 * Yang dijaga: pesannya TIDAK membawa tautan bayar (tautan hanya dari nomor admin),
 * tidak menagih yang memang tak perlu ditagih (ambil-toko, tempo, lunas, belum DP),
 * dan satu kabar per sisa tagihan meski dipindai tiap 15 menit.
 */
class PelunasanNoticeTest extends TestCase
{
    use RefreshDatabase;

    private PelunasanNoticeService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true, 'crm.admin_phone' => '08998844666']);
        app(ChatManager::class)->fake()->reset();

        $this->svc = app(PelunasanNoticeService::class);
    }

    private function pelunasan()
    {
        return CrmOutboxMessage::where('event', CrmOutboxMessage::EVENT_PELUNASAN);
    }

    /* ------------------------------------------------------------------ isi pesan */

    public function test_pesan_tanpa_tautan_bayar_dan_menyebut_nomor_admin(): void
    {
        $teks = TemplateResmi::perkenalkanDiri((string) TemplateResmi::render(
            'tagihan_pelunasan',
            ['Alfira', 'SO/2026/09/00010', '95.000', '08998844666']
        ));

        $this->assertStringStartsWith('Halo Kak Alfira, kami dari tim marketing Noud Acrylic Shop. Pesanan SO/2026/09/00010', $teks);
        $this->assertStringContainsString('Rp95.000', $teks);
        $this->assertStringContainsString('08998844666', $teks);
        $this->assertStringNotContainsString('http', $teks);
        $this->assertTrue(TemplateResmi::hanyaWaha('tagihan_pelunasan'));
    }

    public function test_mengantrekan_sisa_tagihan_pada_jam_buka(): void
    {
        $so = $this->salesOrder(['grand_total' => 725000, 'paid_amount' => 630000]);

        [$ok] = $this->svc->kabari($so);

        $this->assertTrue($ok);
        $baris = $this->pelunasan()->firstOrFail();
        $this->assertSame('tagihan_pelunasan', $baris->template_name);
        $this->assertSame(['Budi', $so->order_number, '95.000', '08998844666'], $baris->template_body);
        $this->assertSame(CrmOutboxMessage::STATUS_MENUNGGU, $baris->status);
    }

    /* ---------------------------------------------------------------------- pagar */

    public function test_satu_kabar_per_sisa_tagihan(): void
    {
        $so = $this->salesOrder(['grand_total' => 725000, 'paid_amount' => 630000]);

        $this->assertTrue($this->svc->kabari($so)[0]);
        $this->assertFalse($this->svc->kabari($so)[0]);
        $this->assertSame(1, $this->pelunasan()->count());

        // Pelunasan sebagian mengubah sisanya → satu kabar baru dengan angka yang benar.
        $so->forceFill(['paid_amount' => 700000])->save();
        $this->assertTrue($this->svc->kabari($so->fresh())[0]);
        $this->assertSame(2, $this->pelunasan()->count());
    }

    public function test_tidak_menagih_ambil_toko_tempo_lunas_atau_belum_dp(): void
    {
        $kasus = [
            'ambil toko' => ['delivery_method' => 'ambil_toko', 'grand_total' => 100000, 'paid_amount' => 50000],
            'tempo'      => ['is_tempo' => true, 'grand_total' => 100000, 'paid_amount' => 50000],
            'lunas'      => ['grand_total' => 100000, 'paid_amount' => 100000],
            'belum dp'   => ['grand_total' => 100000, 'paid_amount' => 0],
            'draft'      => ['status' => 'draft', 'grand_total' => 100000, 'paid_amount' => 50000],
        ];

        foreach ($kasus as $nama => $atribut) {
            $this->assertFalse($this->svc->kabari($this->salesOrder($atribut))[0], "Kasus {$nama} tidak boleh ditagih.");
        }

        $this->assertSame(0, $this->pelunasan()->count());
    }

    public function test_tombol_manual_mencoba_lagi_kabar_yang_dulu_dilewati(): void
    {
        $so = $this->salesOrder(['grand_total' => 200000, 'paid_amount' => 100000], ['phone' => null]);

        $this->assertFalse($this->svc->kabari($so)[0]);
        $this->assertSame(CrmOutboxMessage::STATUS_DILEWATI, $this->pelunasan()->firstOrFail()->status);

        $so->customer->forceFill(['phone' => '081234567890'])->save();
        $so = $so->fresh('customer');

        // Pemindaian otomatis tidak mengulang...
        $this->assertFalse($this->svc->kabari($so)[0]);
        // ...tombol manual mengulang.
        $this->assertTrue($this->svc->kabari($so, manual: true)[0]);
        $this->assertSame(CrmOutboxMessage::STATUS_MENUNGGU, $this->pelunasan()->firstOrFail()->status);
    }

    public function test_instant_ikut_ditagih_seperti_kurir(): void
    {
        $so = $this->salesOrder(['delivery_method' => 'instant', 'grand_total' => 150000, 'paid_amount' => 75000]);

        $this->assertTrue($this->svc->kabari($so)[0]);
    }

    /* ------------------------------------------------- ambil-toko: sisa di pesan siap */

    public function test_siap_diambil_menyebut_sisa_bayar_di_waha_tapi_tidak_di_jalur_resmi(): void
    {
        $so = $this->salesOrder([
            'delivery_method' => 'ambil_toko', 'pickup_code' => '0161',
            'grand_total' => 725000, 'paid_amount' => 630000,
        ]);

        $baris = app(\App\Modules\CRM\Services\OrderNotificationService::class)->antrekanSiapDiambil($so);
        $body  = $baris->template_body;

        $this->assertCount(5, $body);
        $this->assertSame('95.000', $body[4]);

        $teks = TemplateResmi::render('pesanan_siap_diambil', $body);
        $this->assertStringContainsString(
            'kepada petugas. Masih ada sisa pembayaran sebesar Rp95.000 yang dapat Kakak lunasi di kasir saat pengambilan. Kami buka',
            $teks
        );

        // Template Meta hanya punya empat variabel — yang kelima dipangkas.
        $this->assertCount(4, TemplateResmi::variabelResmi('pesanan_siap_diambil', $body));
    }

    public function test_siap_diambil_yang_sudah_lunas_tetap_bunyi_template(): void
    {
        $so = $this->salesOrder(['delivery_method' => 'ambil_toko', 'grand_total' => 100000, 'paid_amount' => 100000]);

        $body = app(\App\Modules\CRM\Services\OrderNotificationService::class)->antrekanSiapDiambil($so)->template_body;

        $this->assertCount(4, $body);
        $this->assertStringNotContainsString('sisa pembayaran', TemplateResmi::render('pesanan_siap_diambil', $body));
    }

    /* ---------------------------------------------------------------- dua pintu */

    public function test_pemindaian_hanya_mengabari_pesanan_kirim_di_belum_lunas(): void
    {
        $kirim = $this->salesOrder(['grand_total' => 300000, 'paid_amount' => 100000]);
        $ambil = $this->salesOrder(['delivery_method' => 'ambil_toko', 'grand_total' => 300000, 'paid_amount' => 100000]);

        $this->mock(FulfillmentReadinessService::class, function ($m) use ($kirim, $ambil) {
            $m->shouldReceive('bucket')->with('belum_lunas')->andReturn(collect([
                ['id' => $kirim->id, 'is_pickup' => false],
                ['id' => $ambil->id, 'is_pickup' => true],
            ]));
        });

        $hasil = app(PelunasanNoticeService::class)->pindaiOtomatis();

        $this->assertSame([$kirim->order_number], $hasil['nomor']);
        $this->assertSame(1, $this->pelunasan()->count());
    }

    public function test_tombol_di_pemrosesan_pesanan_mengantrekan_kabar(): void
    {
        $so = $this->salesOrder(['grand_total' => 300000, 'paid_amount' => 100000]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->from('/erp/pos/fulfillment/perlu-diproses')
            ->post(route('pos.fulfillment.kabari-pelunasan', $so->id))
            ->assertRedirect('/erp/pos/fulfillment/perlu-diproses')
            ->assertSessionHas('success');

        $this->assertSame(1, $this->pelunasan()->count());
    }

    /* ------------------------------------------------------------------ bantuan */

    private function salesOrder(array $so = [], array $customer = []): SalesOrder
    {
        $cust = Customer::create($customer + [
            'code'      => 'CUST-' . uniqid(),
            'name'      => 'Budi',
            'phone'     => '628998844666',
            'is_active' => true,
        ]);

        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Uji Pelunasan']);

        return SalesOrder::create($so + [
            'order_number'    => 'SO-LUNAS-' . uniqid(),
            'customer_id'     => $cust->id,
            'warehouse_id'    => $wh->id,
            'order_date'      => now()->toDateString(),
            'status'          => 'confirmed',
            'delivery_method' => 'kurir',
            'grand_total'     => 100000,
        ])->load('customer');
    }
}
