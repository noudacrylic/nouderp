<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status 'partial' — sebagian hasil sudah dilepas ke stok, produksi masih berjalan
 * untuk sisanya. Order berstatus ini TETAP dihitung sebagai produksi aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','confirmed','in_progress','partial','completed','pending','finalized','cancelled','merged') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','confirmed','in_progress','completed','pending','finalized','cancelled','merged') NOT NULL DEFAULT 'draft'");
    }
};
