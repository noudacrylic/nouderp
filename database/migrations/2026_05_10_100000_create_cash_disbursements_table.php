<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            // general | freight | customer_refund
            $table->string('type', 30)->default('general');
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            // Optional context references
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('payee')->nullable(); // Untuk freight: nama jasa kirim, untuk general: nama vendor/keterangan
            $table->string('reference')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_disbursements');
    }
};
