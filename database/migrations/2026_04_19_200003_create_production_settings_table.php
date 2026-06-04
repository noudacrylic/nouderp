<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_settings', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('score_sales_period')->default(1); // 1 = 1 bulan, 3 = 3 bulan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_settings');
    }
};
