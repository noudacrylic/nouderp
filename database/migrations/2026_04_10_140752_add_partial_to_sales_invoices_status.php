<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL to modify ENUM because native Laravel change() can be tricky with ENUM
        DB::statement("ALTER TABLE sales_invoices MODIFY status ENUM('draft', 'posted', 'void', 'paid', 'partial') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sales_invoices MODIFY status ENUM('draft', 'posted', 'void', 'paid') NOT NULL DEFAULT 'draft'");
    }
};
