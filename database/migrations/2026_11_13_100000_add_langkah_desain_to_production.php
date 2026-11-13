<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langkah Desain di OP: cetak (dan tempel) stiker oleh Admin Desain, disisipkan di urutan mana
 * pun (mis. CNC → Desain → CNC). Bukan divisi produksi: tanpa timer, tanpa pelaksana mesin —
 * ditutup dari kartu Meja Kerja, langkah berikutnya menunggu seperti biasa.
 *   desain = 'tempel' → Desain mencetak & menempel; 'cetak' → Desain mencetak, ditempel Assembling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->string('desain', 10)->nullable()->after('is_qc');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_steps', fn (Blueprint $t) => $t->dropColumn('desain'));
    }
};
