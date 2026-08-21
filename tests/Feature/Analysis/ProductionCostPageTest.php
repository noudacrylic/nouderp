<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use App\Modules\Analysis\Models\ProductionCostComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman Biaya & Tarif Divisi benar-benar merender pohonnya.
 *
 * Perhitungannya sudah diuji di ProductionCostRateServiceTest — yang dijaga di sini
 * adalah hal yang tidak terlihat dari service: blade-nya kompilasi dan grup baru
 * muncul lengkap dengan form tambah barisnya.
 */
class ProductionCostPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_menampilkan_kepala_packing_dengan_tarif_per_transaksi(): void
    {
        ProductionCostComponent::create([
            'group_key'      => 'packing',
            'name'           => 'Overhead Packing',
            'source'         => 'manual',
            'amount_monthly' => 2_000_000,
            'is_active'      => true,
        ]);

        DB::table('sales_deliveries')->insert([
            'delivery_number' => 'SJ-1',
            'warehouse_id'    => 1,
            'delivery_date'   => now()->startOfMonth()->toDateString(),
            'status'          => 'posted',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($user)
            ->get(route('analisa.biaya-divisi.index', ['months' => 1]));

        $response->assertOk()
            ->assertSee('Overhead Packing')
            // Halaman ini kini hanya daftar + jumlah: pembagi & tarif sudah pindah ke Kuota
            // Produksi. Kalau kolomnya kembali muncul, artinya kerumitan yang sengaja dibuang
            // menyelinap masuk lagi.
            ->assertDontSee('Pembagi</th>', false)
            ->assertDontSee('/transaksi')
            ->assertSee('Total Fixed Cost')
            // Form tambah baris grup Packing ikut terpasang, bukan cuma barisnya.
            ->assertSee('addcmp-packing');
    }
}
