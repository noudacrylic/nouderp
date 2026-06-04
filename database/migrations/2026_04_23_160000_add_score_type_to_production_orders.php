<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->enum('score_type', ['auto', 'priority'])->default('auto')->after('repair_source_id');
            $table->enum('priority_level', ['low', 'medium', 'high', 'very_high'])->nullable()->after('score_type');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['score_type', 'priority_level']);
        });
    }
};
