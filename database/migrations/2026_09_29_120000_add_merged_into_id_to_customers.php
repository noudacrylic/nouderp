<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak penggabungan pelanggan kembar: baris yang digabung tidak dihapus, hanya
 * diarsipkan dan menunjuk ke pelanggan yang menampungnya (CustomerMergeService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('is_active')
                ->constrained('customers')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('merged_at');
        });
    }
};
