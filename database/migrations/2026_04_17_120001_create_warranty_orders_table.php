<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_orders', function (Blueprint $table) {
            $table->id();
            $table->string('warranty_number')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('invoice_id')->nullable()->constrained('sales_invoices');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('warranty_date');
            $table->enum('type', ['replacement', 'repair']);
            $table->enum('status', ['draft', 'received', 'repaired', 'shipped'])->default('draft');
            $table->text('issue_description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_orders');
    }
};
