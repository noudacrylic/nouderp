<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('admin_fee_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('admin_fee', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
