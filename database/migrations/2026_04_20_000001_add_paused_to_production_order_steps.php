<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE production_order_steps MODIFY COLUMN status ENUM('pending','in_progress','paused','completed') NOT NULL DEFAULT 'pending'");

        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->datetime('paused_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE production_order_steps SET status = 'pending' WHERE status = 'paused'");
        DB::statement("ALTER TABLE production_order_steps MODIFY COLUMN status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending'");

        Schema::table('production_order_steps', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
