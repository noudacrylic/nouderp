<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QC Timbang (17 Okt 2026) — hasil produksi ready stock produk knockdown ditimbang
 * per bungkus lalu dibandingkan dengan berat acuan dari sampel yang sudah dicek benar.
 *
 * Acuan diambil dari PENIMBANGAN, bukan perhitungan: tebal lembaran akrilik punya
 * toleransi pabrik sehingga berat panel bisa bergeser beberapa persen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Satu profil QC per SKU. Adanya profil aktif = produk ini wajib QC timbang.
        Schema::create('qc_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->decimal('packaging_gram', 10, 1)->default(0);     // plastik / bungkus
            $table->decimal('target_gram', 10, 1)->nullable();         // rata-rata sampel paket utuh
            $table->json('samples')->nullable();                       // berat sampel paket utuh
            $table->string('tolerance_mode', 10)->nullable();          // gram|persen; null = ikut default
            $table->decimal('tolerance_value', 8, 2)->nullable();
            $table->string('batch_note', 190)->nullable();             // batch lembaran saat sampel
            $table->timestamp('sampled_at')->nullable();
            $table->foreignId('sampled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('qc_profile_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_profile_id')->constrained('qc_profiles')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('qty')->default(1);                // per bungkus
            $table->decimal('unit_gram', 10, 1)->nullable();           // rata-rata sampel per keping
            $table->json('samples')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Arsip acuan lama setiap "ambil ulang acuan" (mis. ganti batch lembaran).
        Schema::create('qc_reference_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_profile_id')->constrained('qc_profiles')->cascadeOnDelete();
            $table->json('snapshot');
            $table->string('reason', 190)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Satu baris per penimbangan bungkus (atau per pelepasan admin).
        Schema::create('qc_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('qc_profile_id')->nullable()->constrained('qc_profiles')->nullOnDelete();
            $table->string('mode', 12)->default('normal');             // normal|perbaikan|lepas
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('target_gram', 10, 1)->nullable();          // dibekukan saat timbang
            $table->decimal('tolerance_gram', 8, 1)->nullable();
            $table->decimal('actual_gram', 10, 1)->nullable();
            $table->decimal('diff_gram', 10, 1)->nullable();
            $table->string('result', 10);                              // lulus|kurang|lebih|dilepas
            $table->json('analysis')->nullable();
            // Bungkus gagal menunggu perbaikan → ditutup oleh timbang perbaikan yang lulus.
            $table->string('repair_status', 12)->nullable();           // menunggu|diperbaiki
            $table->foreignId('repaired_by_check_id')->nullable()->constrained('qc_checks')->nullOnDelete();
            $table->string('source', 12)->default('timbangan');        // timbangan|simulasi|manual
            $table->text('raw_frame')->nullable();                     // hex mentah dari timbangan
            $table->string('reason', 255)->nullable();                 // alasan pelepasan
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_order_id', 'product_id']);
        });

        // Toleransi bawaan (layar Setting Toleransi).
        Schema::table('production_settings', function (Blueprint $table) {
            $table->string('qc_tolerance_mode', 10)->nullable();
            $table->decimal('qc_tolerance_value', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_settings', function (Blueprint $table) {
            $table->dropColumn(['qc_tolerance_mode', 'qc_tolerance_value']);
        });
        Schema::dropIfExists('qc_checks');
        Schema::dropIfExists('qc_reference_history');
        Schema::dropIfExists('qc_profile_components');
        Schema::dropIfExists('qc_profiles');
    }
};
