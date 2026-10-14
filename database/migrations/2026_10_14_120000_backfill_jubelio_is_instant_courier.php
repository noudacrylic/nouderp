<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tandai ulang pesanan marketplace berkurir instant.
 *
 * Pendeteksi lama mempercayai flag `is_instant_courier` dari Jubelio, yang
 * SELALU false untuk Shopee — termasuk "SPX Instant" & "Instant Prioritas".
 * Akibatnya tak satu pun pesanan pernah tertandai instant. Kata kuncinya sama
 * dengan JubelioOrderSyncService::isInstantCourier().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jubelio_order_links')
            ->where('is_instant_courier', 0)
            ->where(function ($q) {
                foreach (['instant', 'gosend', 'gojek', 'grab', 'sameday', 'same day'] as $kw) {
                    $q->orWhereRaw('LOWER(shipper) LIKE ?', ["%{$kw}%"]);
                }
            })
            ->update(['is_instant_courier' => 1]);
    }

    public function down(): void
    {
        // Sengaja kosong: nilai lama memang keliru.
    }
};
