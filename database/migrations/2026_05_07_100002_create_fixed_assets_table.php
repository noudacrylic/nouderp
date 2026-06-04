<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();

            $table->string('asset_code', 50)->unique();
            $table->string('name', 200);
            $table->string('description', 500)->nullable();

            $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->string('responsible_person', 150)->nullable();
            $table->string('serial_number', 100)->nullable();

            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->boolean('is_depreciable')->default(true);

            $table->date('depreciation_start_date')->nullable();
            $table->date('last_depreciation_date')->nullable();
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('current_book_value', 18, 2)->default(0);
            $table->unsignedInteger('months_depreciated')->default(0);

            $table->enum('source_type', ['manual', 'purchase'])->default('manual');
            $table->foreignId('source_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->foreignId('source_invoice_item_id')->nullable()->constrained('purchase_invoice_items')->nullOnDelete();

            $table->enum('status', ['draft', 'active', 'disposed', 'voided'])->default('draft');

            $table->foreignId('journal_acquisition_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('journal_disposal_id')->nullable()->constrained('journals')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->string('posted_by', 100)->nullable();
            $table->date('disposed_date')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('created_by', 100)->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('asset_category_id');
            $table->index('warehouse_id');
            $table->index('source_invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
