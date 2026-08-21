<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asumsi untuk halaman Kuota Produksi.
 *
 * Kuota dihitung dari data nyata (waktu produksi terukur × jumlah slot × jam kerja), tapi data
 * nyata punya dua kelemahan: ada produk yang sampelnya masih jelek, dan ia hanya bisa bercerita
 * tentang MASA LALU. Dua tabel ini menyimpan angka pengandaian di sebelah angka nyatanya, plus
 * saklar per baris untuk memilih yang mana yang dipakai — supaya halaman ini bisa dipakai untuk
 * memproyeksikan ("kalau mesinnya jadi 4?") tanpa memalsukan data terukurnya.
 *
 * Angka nyata TIDAK PERNAH ditimpa: asumsi disimpan di kolomnya sendiri, dan yang menentukan
 * hanya `use_assumption`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Asumsi per divisi: berapa slot, berapa jam sehari, berapa hari sebulan.
        Schema::create('production_quota_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->unique()->constrained('production_departments')->cascadeOnDelete();
            $table->unsignedSmallInteger('assumed_slots')->nullable();
            $table->decimal('assumed_hours_per_day', 5, 2)->nullable();
            $table->decimal('assumed_working_days', 5, 2)->nullable();
            $table->boolean('use_assumption')->default(false);
            $table->timestamps();
        });

        // Asumsi waktu per unit, per produk per divisi.
        Schema::create('production_quota_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('production_departments')->cascadeOnDelete();
            $table->decimal('assumed_seconds_per_unit', 12, 2)->nullable();
            $table->boolean('use_assumption')->default(false);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_quota_assumptions');
        Schema::dropIfExists('production_quota_settings');
    }
};
