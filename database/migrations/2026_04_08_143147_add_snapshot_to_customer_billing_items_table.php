<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_billing_items', function (Blueprint $table) {
            $table->string('document_number')->nullable()->after('sales_order_id');
            $table->date('document_date')->nullable()->after('document_number');
        });
    }

    public function down(): void
    {
        Schema::table('customer_billing_items', function (Blueprint $table) {
            $table->dropColumn(['document_number', 'document_date']);
        });
    }
};
