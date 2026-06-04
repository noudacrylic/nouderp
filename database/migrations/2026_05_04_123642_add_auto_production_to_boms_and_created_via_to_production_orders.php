<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->boolean('auto_production')->default(false)->after('typical_cycles');
            $table->index('auto_production');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('created_via', 20)->default('manual')->after('status');
            $table->index('created_via');
        });
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropIndex(['auto_production']);
            $table->dropColumn('auto_production');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropIndex(['created_via']);
            $table->dropColumn('created_via');
        });
    }
};
