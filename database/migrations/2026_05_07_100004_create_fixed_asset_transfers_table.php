<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_asset_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('transfer_number', 50)->unique();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->date('transfer_date');
            $table->string('notes', 500)->nullable();

            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
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
        Schema::dropIfExists('fixed_asset_transfers');
    }
};
