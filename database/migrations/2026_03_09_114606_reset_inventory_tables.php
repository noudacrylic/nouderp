<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Schema::dropIfExists('stock_ledgers');
        Schema::dropIfExists('opening_balance_logs');

        Schema::dropIfExists('stock_layers');
        Schema::dropIfExists('product_stocks');

    }

    public function down(): void
    {

        //
        // tidak perlu rollback
        //

    }
};
