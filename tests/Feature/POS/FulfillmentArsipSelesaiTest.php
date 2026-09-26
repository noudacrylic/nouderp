<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tab "Selesai" mengarsip pesanan 3 hari setelah SELESAI — dan yang menentukan
 * "selesai" adalah tanggal MARKETPLACE menyatakannya selesai.
 *
 * Dulu patokannya `wms_completed_at`, yang menyala saat KITA selesai memproses
 * pesanan (picking → faktur → resi terbit). Untuk pesanan marketplace jarak
 * antara "kita kirim" dan "pembeli menerima" biasanya seminggu lebih, jadi
 * pesanan yang baru dituntaskan pembeli hari ini sudah terarsip pada detik ia
 * masuk tab Selesai. Gejalanya: tab "Selesai" selalu nyaris kosong sementara
 * "Dikirim" menumpuk ratusan.
 */
class FulfillmentArsipSelesaiTest extends TestCase
{
    use RefreshDatabase;

    private function warehouseId(): int
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
    }

    private function pesananMarketplace(array $linkAttrs): SalesOrder
    {
        $cust = Customer::create([
            'code'           => 'CUST-' . uniqid(),
            'name'           => 'Shopee Official',
            'is_marketplace' => true,
            'is_active'      => true,
        ]);

        $so = SalesOrder::create([
            'order_number'         => 'SO-' . uniqid(),
            'customer_id'          => $cust->id,
            'warehouse_id'         => $this->warehouseId(),
            'order_date'           => now()->subDays(14)->toDateString(),
            'global_discount_type' => 'nominal',
            'status'               => 'confirmed',
            'grand_total'          => 100000,
            'paid_amount'          => 100000,
        ]);

        JubelioOrderLink::create(array_merge([
            'jubelio_salesorder_id' => random_int(1000, 999999),
            'jubelio_salesorder_no' => 'TP-' . uniqid(),
            'sales_order_id'        => $so->id,
            'store'                 => 'Shopee',
            'dp_posted'             => true,
            'last_status'           => 'completed',
        ], $linkAttrs));

        return $so;
    }

    private function idDiTabSelesai(): array
    {
        return app(FulfillmentReadinessService::class)
            ->bucket('selesai', null, [])
            ->pluck('id')
            ->all();
    }

    /**
     * Diproses sepuluh hari lalu, baru dinyatakan selesai kemarin → MASIH tampil.
     * Inilah keadaan yang dulu langsung terarsip, dan inilah mayoritas pesanan
     * marketplace: dikirim lebih dulu, dituntaskan pembeli belakangan.
     */
    public function test_pesanan_yang_baru_dinyatakan_selesai_masih_tampil_walau_diprosesnya_lama(): void
    {
        $so = $this->pesananMarketplace([
            'wms_completed_at' => now()->subDays(10),   // kita selesai memproses
            'mp_completed_at'  => now()->subDay(),      // marketplace menyatakan selesai
        ]);

        $this->assertContains($so->id, $this->idDiTabSelesai());
    }

    /** Selesai sungguhan lebih dari 3 hari lalu → memang diarsipkan. */
    public function test_pesanan_yang_selesai_lebih_dari_tiga_hari_lalu_diarsipkan(): void
    {
        $so = $this->pesananMarketplace([
            'wms_completed_at' => now()->subDays(10),
            'mp_completed_at'  => now()->subDays(5),
        ]);

        $this->assertNotContains($so->id, $this->idDiTabSelesai());
    }

    /**
     * Baris lama yang belum sempat diisi tanggal marketplace-nya tetap memakai
     * `wms_completed_at`. Tanpa cadangan ini seluruh riwayat akan terbit
     * serentak di tab Selesai begitu kolom barunya ditambahkan.
     */
    public function test_tanpa_tanggal_marketplace_tetap_memakai_tanggal_proses(): void
    {
        $lama = $this->pesananMarketplace(['wms_completed_at' => now()->subDays(10)]);
        $baru = $this->pesananMarketplace(['wms_completed_at' => now()->subDay()]);

        $tampil = $this->idDiTabSelesai();

        $this->assertNotContains($lama->id, $tampil);
        $this->assertContains($baru->id, $tampil);
    }
}
