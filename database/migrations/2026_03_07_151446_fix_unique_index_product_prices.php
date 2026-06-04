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
        Schema::table('product_prices', function (Blueprint $table) {
            // Drop foreign key first because it relies on the index
            $table->dropForeign(['product_id']);

            // Drop old unique index
            $table->dropUnique('product_prices_product_id_channel_unique');

            // Add new unique index including unit_name
            $table->unique(
                ['product_id', 'unit_name', 'channel'],
                'product_prices_product_unit_channel_unique'
            );

            // Re-add foreign key
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['product_id']);

            // Drop new unique index
            $table->dropUnique('product_prices_product_unit_channel_unique');

            // Restore old unique index
            $table->unique(
                ['product_id', 'channel'],
                'product_prices_product_id_channel_unique'
            );

            // Re-add foreign key
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }
};
