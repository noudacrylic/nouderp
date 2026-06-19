<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "pembeli mengajukan pembatalan" yang ditarik dari Jubelio
 * (GET /wms/sales/orders/request-cancel). Dipakai tab "Pembatalan" di
 * Pemrosesan Pesanan agar CS tahu pesanan mana yang diminta batal pembeli,
 * berdampingan dengan SO marketplace yang sudah di-void.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->boolean('cancel_requested')->default(false)->after('is_instant_courier');
            $table->string('cancel_reason')->nullable()->after('cancel_requested');
            $table->timestamp('cancel_requested_at')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn(['cancel_requested', 'cancel_reason', 'cancel_requested_at']);
        });
    }
};
