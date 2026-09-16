<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrder as SO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua peran nomor & rantai jatuh-baliknya.
 *
 * NOMOR UTAMA menerima kabar, NOMOR PENERIMA diberikan ke kurir. Keduanya ada
 * di pusat maupun cabang, dan yang kosong selalu menoleh ke atas — sehingga
 * tidak pernah ada pesanan yang kehilangan penerima kabar cuma karena satu
 * kolom belum sempat diisi.
 *
 * Yang paling perlu dijaga di berkas ini: kabar UANG tidak boleh nyasar ke
 * nomor penerima barang. Itu pernah terjadi, diam-diam, karena urutannya dulu
 * `recipient_phone ?: phone`.
 */
class NomorKontakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        config(['crm.dry_run' => true]);
    }

    private function pelanggan(array $atribut = []): Customer
    {
        return Customer::create(array_merge([
            'code'      => 'CUST-' . uniqid(),
            'name'      => 'PT Sumber Jaya',
            'phone'     => '628111111111',
            'is_active' => true,
        ], $atribut));
    }

    private function salesOrder(Customer $customer): SalesOrder
    {
        return SO::create([
            'order_number' => 'SO-NK-' . uniqid(),
            'customer_id'  => $customer->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Uji Nomor'])->id,
            'order_date'   => now(),
            'status'       => 'draft',
            'grand_total'  => 500000,
        ]);
    }

    /* ------------------------------------------------------------ rantai nomor */

    public function test_nomor_penerima_kosong_ikut_nomor_utama(): void
    {
        $customer = $this->pelanggan(['recipient_phone' => null]);

        $this->assertSame('628111111111', $customer->nomorNotifikasi());
        $this->assertSame('628111111111', $customer->nomorPengiriman());
    }

    public function test_nomor_penerima_tidak_pernah_mengambil_alih_kabar(): void
    {
        $customer = $this->pelanggan(['recipient_phone' => '628222222222']);

        // Kurir menelepon penerima barang; kabar tetap ke nomor utama.
        $this->assertSame('628222222222', $customer->nomorPengiriman());
        $this->assertSame('628111111111', $customer->nomorNotifikasi());
    }

    public function test_cabang_tanpa_nomor_ikut_seluruhnya_ke_pusat(): void
    {
        $customer = $this->pelanggan(['recipient_phone' => '628222222222']);
        $cabang = $customer->branches()->create(['name' => 'Cabang Bandung']);

        $this->assertSame('628111111111', $cabang->nomorNotifikasi());
        $this->assertSame('628222222222', $cabang->nomorPengiriman());
    }

    public function test_nomor_utama_cabang_dipakai_untuk_kabar_dan_juga_kurir_bila_penerima_kosong(): void
    {
        $customer = $this->pelanggan(['recipient_phone' => '628222222222']);
        $cabang = $customer->branches()->create([
            'name'  => 'Cabang Bandung',
            'phone' => '628333333333',
        ]);

        $this->assertSame('628333333333', $cabang->nomorNotifikasi());

        // Nomor cabang mana pun mendahului nomor pusat: kurir yang kesasar harus
        // menelepon orang yang ada di lokasi, bukan purchasing di kota lain.
        $this->assertSame('628333333333', $cabang->nomorPengiriman());
    }

    public function test_penerima_cabang_paling_didahulukan_untuk_kurir(): void
    {
        $customer = $this->pelanggan();
        $cabang = $customer->branches()->create([
            'name'            => 'Cabang Bandung',
            'phone'           => '628333333333',
            'recipient_phone' => '628444444444',
        ]);

        $this->assertSame('628444444444', $cabang->nomorPengiriman());
        $this->assertSame('628333333333', $cabang->nomorNotifikasi());
    }

    /* -------------------------------------------------------------- tembusan */

    public function test_nomor_tambahan_ikut_menerima_kabar(): void
    {
        $customer = $this->pelanggan();
        $customer->notificationPhones()->create(['label' => 'Purchasing', 'phone' => '628555555555']);
        $customer->notificationPhones()->create(['label' => 'Lapangan', 'phone' => '628666666666']);

        $so = $this->salesOrder($customer);

        app(OrderNotificationService::class)->antrekanPembayaranDiterima($so, 150000);

        $penerima = CrmOutboxMessage::where('sales_order_id', $so->id)
            ->where('status', CrmOutboxMessage::STATUS_MENUNGGU)
            ->pluck('recipient')->sort()->values()->all();

        $this->assertSame(
            ['628111111111', '628555555555', '628666666666'],
            $penerima
        );
    }

    /** Nomor tambahan yang kembar dengan nomor utama tidak boleh dikirimi dua kali. */
    public function test_nomor_tambahan_yang_sama_dengan_utama_tidak_digandakan(): void
    {
        $customer = $this->pelanggan();
        // Ditulis dengan awalan 0 — bentuk berbeda, orang yang sama.
        $customer->notificationPhones()->create(['phone' => '08111111111']);

        $so = $this->salesOrder($customer);

        app(OrderNotificationService::class)->antrekanPembayaranDiterima($so, 150000);

        $this->assertSame(1, CrmOutboxMessage::where('sales_order_id', $so->id)->count());
    }

    /**
     * Pelanggan yang menyatakan keberatan tidak boleh dikabari lewat pintu
     * belakang: satu baris 'dilewati' beserta alasannya, bukan tembusan.
     */
    public function test_nomor_tambahan_tidak_dikirimi_saat_pelanggan_menolak(): void
    {
        $customer = $this->pelanggan(['wa_opt_out_at' => now()]);
        $customer->notificationPhones()->create(['phone' => '628555555555']);

        $so = $this->salesOrder($customer);

        app(OrderNotificationService::class)->antrekanPembayaranDiterima($so, 150000);

        $this->assertSame(0, CrmOutboxMessage::where('status', CrmOutboxMessage::STATUS_MENUNGGU)->count());
        $this->assertSame(1, CrmOutboxMessage::where('status', CrmOutboxMessage::STATUS_DILEWATI)->count());
    }
}
