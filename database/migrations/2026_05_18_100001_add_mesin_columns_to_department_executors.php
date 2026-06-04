<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->string('mesin_1', 100)->nullable()->after('role');
            $table->string('mesin_2', 100)->nullable()->after('mesin_1');
            $table->string('mesin_3', 100)->nullable()->after('mesin_2');
        });
    }

    public function down(): void
    {
        Schema::table('production_department_executors', function (Blueprint $table) {
            $table->dropColumn(['mesin_1', 'mesin_2', 'mesin_3']);
        });
    }
};
