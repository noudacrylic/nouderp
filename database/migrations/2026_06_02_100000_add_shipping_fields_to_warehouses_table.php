<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('address')->nullable()->after('location');
            $table->string('city')->nullable()->after('address');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code', 10)->nullable()->after('province');
            $table->string('biteship_area_id')->nullable()->after('postal_code');
            $table->string('contact_name')->nullable()->after('biteship_area_id');
            $table->string('contact_phone')->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'address', 'city', 'province', 'postal_code',
                'biteship_area_id', 'contact_name', 'contact_phone',
            ]);
        });
    }
};
