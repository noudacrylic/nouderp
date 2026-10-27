<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee Open Platform tahap 2 — "pesanan bayangan": data Shopee ditarik langsung TANPA
 * menulis ke SO/stok, lalu dibandingkan dengan SO yang masuk lewat Jubelio.
 *
 * - shopee_items: satu baris per varian (model_id 0 = item tanpa varian). product_id = peta
 *   SKU Shopee ↔ produk ERP; peta_sumber 'sku' (cocok otomatis) atau 'manual' (dipasang
 *   orang, tak ditimpa tarikan berikutnya).
 * - shopee_orders / shopee_order_items: salinan pesanan + baris; sales_order_id diisi bila
 *   SO Jubelio ber-customer_po_number 'SP-<order_sn>' ditemukan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_items', function (Blueprint $table) {
            $table->id();
            $table->string('env', 10);
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('model_id')->default(0);
            $table->string('item_sku', 100)->nullable();
            $table->string('model_sku', 100)->nullable();
            $table->string('nama', 255);
            $table->string('nama_model', 255)->nullable();
            $table->decimal('harga', 15, 2)->nullable();
            $table->integer('stok')->nullable();
            $table->string('status', 30)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('peta_sumber', 10)->nullable();
            $table->timestamp('disinkron_at')->nullable();
            $table->timestamps();

            $table->unique(['env', 'shop_id', 'item_id', 'model_id']);
            $table->index('product_id');
        });

        Schema::create('shopee_orders', function (Blueprint $table) {
            $table->id();
            $table->string('env', 10);
            $table->unsignedBigInteger('shop_id');
            $table->string('order_sn', 40);
            $table->string('status', 30);
            $table->timestamp('dibuat_shopee_at')->nullable();
            $table->timestamp('diperbarui_shopee_at')->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->string('kurir', 100)->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->string('hasil_banding', 30)->nullable();
            $table->text('catatan_banding')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('disinkron_at')->nullable();
            $table->timestamps();

            $table->unique(['env', 'shop_id', 'order_sn']);
            $table->index('hasil_banding');
            $table->index('dibuat_shopee_at');
        });

        Schema::create('shopee_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopee_order_id')->constrained('shopee_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('model_id')->default(0);
            $table->string('sku', 100)->nullable();
            $table->string('nama', 255);
            $table->string('nama_model', 255)->nullable();
            $table->integer('qty');
            $table->decimal('harga_asli', 15, 2)->nullable();
            $table->decimal('harga_diskon', 15, 2)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_order_items');
        Schema::dropIfExists('shopee_orders');
        Schema::dropIfExists('shopee_items');
    }
};
