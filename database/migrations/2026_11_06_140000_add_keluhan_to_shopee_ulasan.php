<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ulasan bagus (⭐4–5) yang ternyata menyelipkan keluhan → kartu admin seperti ulasan jelek.
 * sumber_balasan: template | ai | admin (dari kartu) | shopee (dibalas di Seller Centre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_ulasan', function (Blueprint $t) {
            $t->boolean('keluhan')->nullable()->after('editable');
            $t->string('sumber_balasan', 20)->nullable()->after('dibalas_oleh');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_ulasan', function (Blueprint $t) {
            $t->dropColumn(['keluhan', 'sumber_balasan']);
        });
    }
};
