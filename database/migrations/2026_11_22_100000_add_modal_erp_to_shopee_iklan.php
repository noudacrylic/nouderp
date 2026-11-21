<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modal harian yang dijaga ERP per iklan anggota kelompok. Shopee menaikkan modal iklan yang
 * dijeda lalu dilanjutkan ke modal minimal terbaru (Rp25 rb per 21 Nov 2026, tak bisa
 * diturunkan lagi), jadi belanja harian dikendalikan ERP: begitu belanja hari ini mencapai
 * modal_erp, iklan dijeda sampai besok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_iklan', function (Blueprint $t) {
            $t->decimal('modal_erp', 14, 2)->nullable()->after('modal_harian');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_iklan', function (Blueprint $t) {
            $t->dropColumn('modal_erp');
        });
    }
};
