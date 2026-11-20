<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iklan Shopee (Ads API) — tahap baca saja.
 *  - shopee_iklan     : salinan pengaturan tiap iklan produk (status, modal harian, target ROAS).
 *  - shopee_iklan_jam : kinerja per iklan per jam; campaign_id 0 = total semua iklan toko
 *                       (get_all_cpc_ads_hourly_performance), termasuk iklan yang sudah selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_iklan', function (Blueprint $t) {
            $t->id();
            $t->string('env', 10);
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('campaign_id');
            $t->string('ad_type', 20)->nullable();          // manual | auto
            $t->string('nama', 255)->nullable();
            $t->string('status', 20)->nullable();           // ongoing | paused | ended | closed | scheduled
            $t->string('bidding_method', 20)->nullable();   // auto | manual
            $t->string('placement', 20)->nullable();        // all | search | discovery
            $t->decimal('modal_harian', 14, 2)->default(0); // 0 = tanpa batas
            $t->decimal('roas_target', 8, 2)->nullable();
            $t->json('item_ids')->nullable();
            // Tanggal bisnis: nullable supaya MariaDB tak memberi ON UPDATE CURRENT_TIMESTAMP.
            $t->timestamp('mulai_at')->nullable();
            $t->timestamp('selesai_at')->nullable();
            $t->timestamp('disinkron_at')->nullable();
            $t->timestamps();

            $t->unique(['env', 'campaign_id']);
            $t->index('status');
        });

        Schema::create('shopee_iklan_jam', function (Blueprint $t) {
            $t->id();
            $t->string('env', 10);
            $t->unsignedBigInteger('campaign_id');          // 0 = total toko
            $t->date('tanggal');
            $t->unsignedTinyInteger('jam');
            $t->unsignedInteger('tayang')->default(0);
            $t->unsignedInteger('klik')->default(0);
            $t->decimal('biaya', 14, 2)->default(0);
            $t->unsignedInteger('pesanan')->default(0);     // broad_order
            $t->decimal('gmv', 14, 2)->default(0);          // broad_gmv
            $t->unsignedInteger('pesanan_langsung')->default(0);
            $t->decimal('gmv_langsung', 14, 2)->default(0);
            $t->timestamps();

            $t->unique(['env', 'campaign_id', 'tanggal', 'jam']);
            $t->index(['env', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_iklan_jam');
        Schema::dropIfExists('shopee_iklan');
    }
};
