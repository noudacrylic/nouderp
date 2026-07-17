<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // No. faktur pemasok (referensi audit; dicetak/dicocokkan dgn nota fisik pemasok)
            $table->string('supplier_invoice_no')->nullable()->after('expected_date')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['supplier_invoice_no']);
            $table->dropColumn('supplier_invoice_no');
        });
    }
};
