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
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_adjustments', 'product_id')) {
                $table->dropForeign(['product_id']);
                $table->dropColumn(['product_id', 'qty_system', 'qty_actual']);
            }
            if (Schema::hasColumn('inventory_adjustments', 'adjustment_number')) {
                $table->dropColumn('adjustment_number');
            }

            $table->string('number')->unique()->after('id');
            $table->string('title')->nullable()->after('number');
            $table->string('type')->nullable()->after('title');
            $table->text('notes')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropColumn(['number', 'title', 'type', 'notes']);
            $table->string('adjustment_number')->unique()->after('id');
            $table->unsignedBigInteger('product_id')->after('warehouse_id');
            $table->decimal('qty_system', 18, 4)->after('product_id');
            $table->decimal('qty_actual', 18, 4)->after('qty_system');

            $table->foreign('product_id')->references('id')->on('products');
        });
    }
};
