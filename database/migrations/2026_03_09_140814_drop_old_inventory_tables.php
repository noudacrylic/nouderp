<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('fifo_layers');
        Schema::dropIfExists('product_opening_balances');
        Schema::dropIfExists('product_stocks_old');

        // Also drop tables we just created to make room for the new structure in Phase 2
        Schema::dropIfExists('stock_layers');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('stock_ledgers');
        Schema::dropIfExists('stock_reservations');
    }

    public function down()
    {
    }
};
