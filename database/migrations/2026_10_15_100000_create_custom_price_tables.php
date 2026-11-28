<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harga Custom — menghitung harga produk yang belum pernah dibuat dari ukurannya.
 *
 * Tiga tabel:
 *  - categories: satu baris per jenis produk custom (Kotak, Tempat Obat, …). Isinya angka yang
 *    jarang diubah: biaya setup per pesanan, markup, % sisa bahan, saklar minimal order, divisi
 *    potong & rakit, parameter resep, dan faktor waktu yang dikunci manual.
 *  - materials: tebal & kecepatan potong per bahan lembaran. Ukuran lembarnya TIDAK di sini —
 *    sudah ada di production_raw_materials, satu angka dihitung di satu tempat saja.
 *  - samples: OP yang sudah selesai + ukurannya. Ukuran tidak tercatat di OP lama, jadi diisi
 *    tangan; waktunya dibaca langsung dari timer, tidak disalin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_price_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->decimal('setup_cost', 15, 2)->default(0);
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->decimal('waste_percent', 8, 2)->default(15);
            $table->boolean('min_order_enabled')->default(true);
            $table->unsignedBigInteger('cnc_department_id')->nullable();
            $table->unsignedBigInteger('assembly_department_id')->nullable();
            $table->json('params')->nullable();
            // Null = ikut data sampel. Terisi = dikunci manual.
            $table->decimal('cnc_factor_manual', 8, 3)->nullable();
            $table->decimal('assembly_factor_manual', 8, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('custom_price_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->decimal('tebal_mm', 6, 2)->nullable();
            $table->decimal('cut_speed', 8, 2)->nullable(); // mm per detik
            $table->timestamps();
        });

        Schema::create('custom_price_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('custom_price_categories')->cascadeOnDelete();
            $table->unsignedBigInteger('production_order_id');
            $table->decimal('units', 12, 2);
            $table->json('input');
            $table->boolean('excluded')->default(false);
            $table->string('exclude_reason', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'production_order_id']);
        });

        $cnc = DB::table('production_departments')->where('name', 'like', '%CNC%')->value('id');
        $asm = DB::table('production_departments')->where('name', 'like', '%ssembl%')->value('id');

        // Angka awal dari keputusan pemilik 15 Okt 2026: setup Kotak Rp100rb, sisa 15%,
        // markup 60% sebagai titik awal kalibrasi (bukan 40% spreadsheet — tarif ERP jauh lebih
        // rendah dari Rp99rb/jam spreadsheet, jadi 40% akan menghasilkan harga terlalu murah).
        DB::table('custom_price_categories')->insert([
            'code'                   => 'kotak',
            'name'                   => 'Kotak',
            'setup_cost'             => 100000,
            'markup_percent'         => 60,
            'waste_percent'          => 15,
            'min_order_enabled'      => true,
            'cnc_department_id'      => $cnc,
            'assembly_department_id' => $asm,
            'params'                 => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_price_samples');
        Schema::dropIfExists('custom_price_materials');
        Schema::dropIfExists('custom_price_categories');
    }
};
