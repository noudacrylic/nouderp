<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();

            $table->string('disposal_number', 50)->unique();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();

            $table->date('disposal_date');
            $table->enum('disposal_type', ['sale', 'damage', 'loss', 'donation', 'scrap'])->default('sale');

            $table->decimal('proceeds_amount', 18, 2)->default(0);
            $table->foreignId('proceeds_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->decimal('book_value_at_disposal', 18, 2)->default(0);
            $table->decimal('gain_loss_amount', 18, 2)->default(0);

            $table->string('notes', 500)->nullable();

            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('posted_by', 100)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('created_by', 100)->nullable();

            $table->timestamps();

            $table->index(['fixed_asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
