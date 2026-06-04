<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->foreignId('marketplace_config_id')->constrained('marketplace_configs')->restrictOnDelete();
            $table->string('source_filename')->nullable();
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_fee_actual', 18, 2)->default(0);
            $table->decimal('total_fee_config', 18, 2)->default(0);
            $table->decimal('total_fee_diff', 18, 2)->default(0);
            $table->decimal('total_net', 18, 2)->default(0);
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index(['marketplace_config_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_settlements');
    }
};
