<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah lembar per merk akrilik ({"MAG":2,"NN":1}) — OP campur jadi terukur porsinya.
 * merk_bahan (daftar merk) tetap diisi demi kecocokan dengan data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->json('merk_jumlah')->nullable()->after('merk_bahan');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', fn (Blueprint $t) => $t->dropColumn('merk_jumlah'));
    }
};
