<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_departments', function (Blueprint $table) {
            $table->enum('type', ['produksi', 'non_produksi'])->default('produksi')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('production_departments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
