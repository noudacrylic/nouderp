<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu produk boleh punya beberapa sampel acuan (mis. akrilik 2 mm NN & 1,8 mm), dipilih saat
     * menimbang. Profil yang sudah ada otomatis menjadi "Sampel 1". Indeks gabungan dibuat lebih
     * dulu karena foreign key product_id butuh indeks saat unique lamanya dilepas.
     */
    public function up(): void
    {
        Schema::table('qc_profiles', function (Blueprint $table) {
            $table->string('label', 60)->default('Sampel 1')->after('product_id');
        });
        Schema::table('qc_profiles', function (Blueprint $table) {
            $table->unique(['product_id', 'label']);
        });
        Schema::table('qc_profiles', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
        });
    }

    /** Gagal tanpa mengubah apa pun bila masih ada produk dengan lebih dari satu sampel. */
    public function down(): void
    {
        Schema::table('qc_profiles', function (Blueprint $table) {
            $table->unique('product_id');
        });
        Schema::table('qc_profiles', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'label']);
        });
        Schema::table('qc_profiles', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
