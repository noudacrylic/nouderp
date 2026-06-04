<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();

            $table->string('payment_number')->unique();
            $table->date('payment_date');

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            $table->enum('payment_method', ['cash', 'bank', 'transfer'])->default('bank');
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();

            $table->decimal('amount', 18, 2);
            $table->decimal('allocated_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);

            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->unsignedBigInteger('journal_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
