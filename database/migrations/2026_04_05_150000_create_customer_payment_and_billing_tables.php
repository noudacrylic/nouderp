<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. customer_payments
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->date('date');
            $table->foreignId('cash_account_id')->constrained('accounts'); // akun kas/bank
            $table->decimal('amount', 18, 2);
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 2. customer_payment_allocations
        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('sales_invoices');
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        // 3. customer_billings
        Schema::create('customer_billings', function (Blueprint $table) {
            $table->id();
            $table->string('billing_number')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->enum('status', ['draft', 'open', 'partial', 'paid', 'void'])->default('draft');
            $table->timestamps();
        });

        // 4. customer_billing_items
        Schema::create('customer_billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_billing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('sales_invoices');
            $table->decimal('amount_snapshot', 18, 2);
            $table->decimal('remaining_snapshot', 18, 2);
            $table->timestamps();
        });

        // Add paid_amount to sales_invoices
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 18, 2)->default(0)->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
        Schema::dropIfExists('customer_billing_items');
        Schema::dropIfExists('customer_billings');
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
    }
};
