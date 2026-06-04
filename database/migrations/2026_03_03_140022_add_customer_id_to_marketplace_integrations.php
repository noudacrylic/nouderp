<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->after('id')->nullable();
            $table->dropColumn(['code', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_integrations', function (Blueprint $table) {
            $table->dropColumn('customer_id');
            $table->string('code')->nullable();
            $table->string('name')->nullable();
        });
    }
};
