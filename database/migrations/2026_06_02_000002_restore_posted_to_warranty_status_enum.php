<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kembalikan nilai 'posted' ke enum status warranty_orders.
 * Sebelumnya ter-drop oleh 2026_05_06_062017_add_void_to_warranty_orders_status_enum,
 * sehingga WarrantyOrderService::receive() gagal saat set status 'posted'
 * (Data truncated) → jurnal Dr 1131 / Cr 1132 ikut rollback → akun 1132 tak pernah terisi.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE warranty_orders MODIFY COLUMN status
            ENUM('draft','received','posted','repaired','shipped','void','cancelled')
            NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE warranty_orders MODIFY COLUMN status
            ENUM('draft','received','repaired','shipped','void','cancelled')
            NOT NULL DEFAULT 'draft'");
    }
};
