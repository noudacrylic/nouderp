<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `sales_orders` MODIFY COLUMN `status` ENUM('draft','confirmed','cancelled','void') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `sales_orders` SET `status` = 'cancelled' WHERE `status` = 'void'");
        DB::statement("ALTER TABLE `sales_orders` MODIFY COLUMN `status` ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
