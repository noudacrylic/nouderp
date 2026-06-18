<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_material_additions', function (Blueprint $table) {
            // Penanda pembatalan (void). Null = aktif. Diisi saat void: stok dikembalikan
            // & jurnal WIP dibalik. Void hanya boleh selagi order masih dikerjakan.
            $table->timestamp('voided_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('production_material_additions', function (Blueprint $table) {
            $table->dropColumn('voided_at');
        });
    }
};
