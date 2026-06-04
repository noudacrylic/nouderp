<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hapus UNIQUE pada `sales_orders.quotation_id`. Constraint ini bikin
     * tidak bisa convert ulang Quotation walau SO sebelumnya sudah di-void.
     * Pencegahan duplikat aktif sudah di-handle di SalesOrderController::store()
     * (cek SO non-void/cancelled untuk quotation yang sama).
     *
     * Urutan: drop FK → drop unique → bikin plain index → recreate FK.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropForeign('sales_orders_quotation_id_foreign');
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropUnique('sales_orders_quotation_id_unique');
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->index('quotation_id', 'sales_orders_quotation_id_index');
            $t->foreign('quotation_id', 'sales_orders_quotation_id_foreign')
              ->references('id')->on('sales_quotations')
              ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropForeign('sales_orders_quotation_id_foreign');
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropIndex('sales_orders_quotation_id_index');
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->unique('quotation_id', 'sales_orders_quotation_id_unique');
            $t->foreign('quotation_id', 'sales_orders_quotation_id_foreign')
              ->references('id')->on('sales_quotations');
        });
    }
};
