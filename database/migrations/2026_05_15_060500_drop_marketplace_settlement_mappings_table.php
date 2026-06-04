<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('marketplace_settlement_mappings');
    }

    public function down(): void
    {
        Schema::create('marketplace_settlement_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_config_id')->constrained('marketplace_configs')->cascadeOnDelete();
            $table->unsignedTinyInteger('header_row_index')->default(0);
            $table->string('col_order_ref')->nullable();
            $table->string('col_settlement_date')->nullable();
            $table->string('col_gross_amount')->nullable();
            $table->string('col_admin_fee')->nullable();
            $table->string('col_net_amount')->nullable();
            $table->json('extra_fee_cols')->nullable();
            $table->timestamps();

            $table->unique('marketplace_config_id');
        });
    }
};
