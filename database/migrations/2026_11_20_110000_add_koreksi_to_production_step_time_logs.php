<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi timer oleh owner (mis. lembur bongkar barang tapi lupa Pending): log pause/lanjut
 * yang disisipkan owner ditandai grup + pembuatnya supaya bisa dibedakan & dibatalkan utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_step_time_logs', function (Blueprint $table) {
            $table->string('koreksi_grup', 26)->nullable()->index()->after('notes');
            $table->unsignedBigInteger('koreksi_by')->nullable()->after('koreksi_grup');
        });
    }

    public function down(): void
    {
        Schema::table('production_step_time_logs', function (Blueprint $table) {
            $table->dropIndex(['koreksi_grup']);
            $table->dropColumn(['koreksi_grup', 'koreksi_by']);
        });
    }
};
