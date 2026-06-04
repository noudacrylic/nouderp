<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('depreciation_date');

            $table->decimal('amount', 18, 2);
            $table->decimal('accumulated_after', 18, 2);
            $table->decimal('book_value_after', 18, 2);

            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->enum('status', ['posted', 'void'])->default('posted');

            $table->timestamps();

            $table->index(['fixed_asset_id', 'period_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
