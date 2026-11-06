<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Modules\Marketplace\Shopee\Models\ShopeeReturn;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kolom "Shopee" di daftar Retur Pelanggan: mana yang perlu dibanding dan sampai kapan.
 */
class ShopeeReturnRingkasanTest extends TestCase
{
    private function retur(array $isi): ShopeeReturn
    {
        return new ShopeeReturn($isi + ['raw' => []]);
    }

    public function test_tenggat_pakai_seller_due_lalu_due_date_awal(): void
    {
        $diterimaBalik = $this->retur(['status' => 'PROCESSING', 'seller_due_at' => '2026-11-06 12:25:54']);
        $this->assertSame('2026-11-06 12:25', $diterimaBalik->tenggatPenjual()->format('Y-m-d H:i'));

        // Pengajuan baru: return_seller_due_date = 0 → batas dari due_date.
        $due = Carbon::parse('2026-11-09 08:59:56', config('app.timezone'));
        $baru = $this->retur(['status' => 'PROCESSING', 'raw' => ['due_date' => $due->getTimestamp()]]);
        $this->assertSame('2026-11-09 08:59', $baru->tenggatPenjual()->format('Y-m-d H:i'));

        $final = $this->retur(['status' => 'ACCEPTED', 'seller_due_at' => '2026-11-02 13:43:50']);
        $this->assertNull($final->tenggatPenjual());
    }

    public function test_ringkasan_per_fase(): void
    {
        $r = ShopeeReturn::ringkasan(collect([
            $this->retur(['status' => 'PROCESSING', 'negotiation_status' => 'PENDING_RESPOND', 'raw' => ['due_date' => now()->addDays(3)->getTimestamp()]]),
        ]));
        $this->assertSame('Jawab tawaran pembeli', $r['label']);
        $this->assertNotNull($r['tenggat']);

        $r = ShopeeReturn::ringkasan(collect([$this->retur(['status' => 'JUDGING', 'seller_due_at' => now()->addHour()])]));
        $this->assertSame('Sedang banding', $r['label']);

        $r = ShopeeReturn::ringkasan(collect([$this->retur(['status' => 'ACCEPTED', 'seller_compensation_status' => 'PENDING_REQUEST'])]));
        $this->assertSame('Selesai, tanpa banding', $r['label']);
        $this->assertSame('kompensasi bisa diajukan', $r['catatan']);
        $this->assertNull($r['tenggat']);

        $r = ShopeeReturn::ringkasan(collect([$this->retur(['status' => 'CLOSED', 'pernah_disengketakan' => true])]));
        $this->assertSame('Selesai (pernah banding)', $r['label']);

        $r = ShopeeReturn::ringkasan(collect([$this->retur(['status' => 'CANCELLED'])]));
        $this->assertSame('Dibatalkan pembeli', $r['label']);
    }
}
