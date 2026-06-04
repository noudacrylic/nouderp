<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `warranty_orders` MODIFY COLUMN `status` ENUM('draft','received','repaired','shipped','void','cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `warranty_orders` SET `status` = 'draft' WHERE `status` IN ('void','cancelled')");
        DB::statement("ALTER TABLE `warranty_orders` MODIFY COLUMN `status` ENUM('draft','received','repaired','shipped') NOT NULL DEFAULT 'draft'");
    }
};
