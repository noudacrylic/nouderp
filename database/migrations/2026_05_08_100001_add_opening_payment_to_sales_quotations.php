<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_quotations', 'opening_text')) {
                $table->text('opening_text')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('sales_quotations', 'payment_terms')) {
                $table->text('payment_terms')->nullable()->after('opening_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            if (Schema::hasColumn('sales_quotations', 'opening_text')) {
                $table->dropColumn('opening_text');
            }
            if (Schema::hasColumn('sales_quotations', 'payment_terms')) {
                $table->dropColumn('payment_terms');
            }
        });
    }
};
