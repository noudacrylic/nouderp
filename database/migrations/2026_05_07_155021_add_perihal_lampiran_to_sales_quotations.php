<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_quotations', 'perihal')) {
                $table->string('perihal', 200)->nullable()->after('quotation_number');
            }
            if (!Schema::hasColumn('sales_quotations', 'lampiran')) {
                $table->string('lampiran', 200)->nullable()->after('perihal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            if (Schema::hasColumn('sales_quotations', 'perihal')) {
                $table->dropColumn('perihal');
            }
            if (Schema::hasColumn('sales_quotations', 'lampiran')) {
                $table->dropColumn('lampiran');
            }
        });
    }
};
