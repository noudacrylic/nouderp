<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Add 'finalized' to status enum on production_orders
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','confirmed','in_progress','completed','finalized','cancelled') NOT NULL DEFAULT 'draft'");

        Schema::table('production_orders', function (Blueprint $table) {
            $table->datetime('finalized_at')->nullable()->after('status');
        });

        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->text('variance_notes')->nullable()->after('qty_produced');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->dropColumn('variance_notes');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('finalized_at');
        });

        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
