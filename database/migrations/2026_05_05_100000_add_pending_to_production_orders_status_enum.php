<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','confirmed','in_progress','completed','pending','finalized','cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','confirmed','in_progress','completed','finalized','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
