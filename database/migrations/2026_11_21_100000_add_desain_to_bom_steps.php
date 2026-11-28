<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langkah Desain (cetak/tempel stiker, tanpa divisi) boleh disimpan di BOM — mis. Akrilik Sign:
 * BAS + stiker → satu langkah "Desain — cetak & tempel". OP dari BOM (manual maupun otomatis)
 * menyalin langkahnya apa adanya. Nilai sama dengan production_order_steps.desain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_steps', function (Blueprint $table) {
            $table->string('desain', 10)->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('bom_steps', fn (Blueprint $t) => $t->dropColumn('desain'));
    }
};
