<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `warranty_orders` MODIFY `status` ENUM('draft','received','posted','repaired','shipped') DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `warranty_orders` MODIFY `status` ENUM('draft','received','repaired','shipped') DEFAULT 'draft'");
    }
};
