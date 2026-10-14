<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use Tests\TestCase;

/**
 * Pendeteksi kurir instant pesanan marketplace.
 *
 * Jubelio mengirim `is_instant_courier: false` untuk semua pesanan Shopee,
 * termasuk yang berkurir "Instant Prioritas" berbatas kirim 1 jam. Flag false
 * itu tidak boleh mengalahkan nama kurirnya.
 */
class JubelioInstantCourierTest extends TestCase
{
    private function cek(array $detail, ?string $shipper = null): bool
    {
        $svc = app(JubelioOrderSyncService::class);
        $m   = new \ReflectionMethod($svc, 'isInstantCourier');
        $m->setAccessible(true);

        return $m->invoke($svc, $detail, $shipper);
    }

    public function test_flag_false_tidak_mengalahkan_nama_kurir_instant(): void
    {
        foreach (['Instant', 'Instant Prioritas', 'SPX Instant', 'GrabExpress Sameday', 'Paxel SAME DAY'] as $kurir) {
            $this->assertTrue($this->cek(['is_instant_courier' => false], $kurir), $kurir);
        }
    }

    public function test_flag_true_tetap_dipercaya(): void
    {
        $this->assertTrue($this->cek(['is_instant_courier' => 'true'], 'J&T REG'));
    }

    public function test_kurir_reguler_bukan_instant(): void
    {
        $this->assertFalse($this->cek(['is_instant_courier' => false], 'SPX Standard'));
        $this->assertFalse($this->cek([], 'J&T REG'));
        $this->assertFalse($this->cek([], null));
    }
}
