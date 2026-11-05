<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Driver kurir instan: Jubelio hanya memberikannya selama pengiriman berjalan. */
    public function up(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->string('driver_name', 100)->nullable()->after('tracking_number');
            $table->string('driver_phone', 30)->nullable()->after('driver_name');
            $table->dateTime('driver_captured_at')->nullable()->after('driver_phone');
        });
    }

    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->dropColumn(['driver_name', 'driver_phone', 'driver_captured_at']);
        });
    }
};
