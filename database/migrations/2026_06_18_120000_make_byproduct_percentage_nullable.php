<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persentase produk sampingan boleh dikosongi: artinya tidak ada % default,
        // sehingga di OP wajib diisi manual (mis. ukuran/komposisi beda per order).
        // Data lama (termasuk yang 0) dibiarkan apa adanya.
        Schema::table('production_byproducts', function (Blueprint $table) {
            $table->decimal('percentage', 8, 4)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('production_byproducts', function (Blueprint $table) {
            $table->decimal('percentage', 8, 4)->default(0)->change();
        });
    }
};
