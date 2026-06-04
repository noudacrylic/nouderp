<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('code_prefix', 8);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();

            $table->unsignedInteger('default_useful_life_months')->default(60);
            $table->decimal('default_salvage_value_percent', 5, 2)->default(10);
            $table->boolean('is_depreciable_default')->default(true);

            $table->foreignId('fixed_asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('depreciation_expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('disposal_gain_loss_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
