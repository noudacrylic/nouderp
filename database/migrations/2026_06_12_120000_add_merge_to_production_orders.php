<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah status 'merged' (task yang diserap ke task induk saat penggabungan).
        DB::statement(
            "ALTER TABLE production_orders MODIFY COLUMN status " .
            "ENUM('draft','confirmed','in_progress','completed','pending','finalized','cancelled','merged') " .
            "NOT NULL DEFAULT 'draft'"
        );

        Schema::table('production_orders', function (Blueprint $table) {
            // OP induk tempat OP ini diserap (null = bukan hasil gabungan).
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('created_via');
            $table->foreign('merged_into_id')->references('id')->on('production_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
            $table->dropColumn('merged_into_id');
        });

        DB::statement(
            "ALTER TABLE production_orders MODIFY COLUMN status " .
            "ENUM('draft','confirmed','in_progress','completed','pending','finalized','cancelled') " .
            "NOT NULL DEFAULT 'draft'"
        );
    }
};
