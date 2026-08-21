<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analisa ▸ Harga Produk — dua tabel.
 *
 * `price_channel_fee_components`  penyusun potongan tiap kanal (adm %, biaya tetap, biaya
 *                                 Jubelio per pesanan). Sengaja daftar baris, bukan sepasang
 *                                 kolom %/Rp seperti MarketplaceConfig, karena yang perlu
 *                                 dibaca saat menetapkan harga adalah rinciannya — dan
 *                                 sebagian penyusunnya (biaya Jubelio) bukan potongan
 *                                 marketplace sehingga TIDAK boleh ikut ke akuntansi.
 *
 * `product_channel_prices`        harga per produk per kanal: harga satuan, harga grosir +
 *                                 minimum belinya, dan % afiliasi. Kanal `website` TIDAK
 *                                 menyimpan harga satuan di sini — harganya harga jual asli
 *                                 di master produk, supaya tidak ada dua sumber kebenaran.
 *
 * Keduanya angka analisa: tidak pernah dijurnal. Yang keluar dari sini hanya harga yang
 * dikirim ke Jubelio saat tombolnya ditekan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_channel_fee_components', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 50)->index();
            $table->string('label');
            $table->decimal('percent', 8, 4)->default(0);
            $table->decimal('fixed', 15, 2)->default(0);
            // Penyusun yang benar-benar potongan marketplace (dipakai juga oleh akuntansi)
            // vs biaya lain yang hanya relevan saat menetapkan harga.
            $table->boolean('include_accounting')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_channel_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('channel', 50);
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->unsignedInteger('wholesale_min_qty')->nullable();
            $table->decimal('affiliate_percent', 5, 2)->nullable();
            // Jejak kirim ke Jubelio: harga yang benar-benar mendarat di toko, supaya
            // kelihatan mana produk yang masih ikut harga dasar.
            $table->decimal('pushed_price', 15, 2)->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'channel']);
        });

        $this->seedComponents();
    }

    /**
     * Isi awal diambil dari potongan akuntansi yang sudah ada (MarketplaceConfig) supaya
     * halaman langsung berisi angka yang benar, ditambah dua biaya yang selama ini hanya
     * hidup di spreadsheet: premi pengembalian Shopee dan biaya Jubelio per pesanan.
     */
    private function seedComponents(): void
    {
        if (!Schema::hasTable('marketplace_configs') || !Schema::hasTable('customers')) {
            return;
        }

        foreach (config('price_channels', []) as $key => $channel) {
            if (($channel['kind'] ?? null) !== 'marketplace') {
                continue;
            }

            $names = array_map('mb_strtolower', $channel['customers'] ?? []);

            $cfg = DB::table('marketplace_configs')
                ->join('customers', 'customers.id', '=', 'marketplace_configs.customer_id')
                ->whereIn(DB::raw('LOWER(customers.name)'), $names)
                // Kanal gabungan (TikTok/Tokopedia): ambil potongan tertinggi — kalau meleset,
                // melesetnya ke arah aman.
                ->orderByDesc('marketplace_configs.admin_fee_percent')
                ->first(['marketplace_configs.admin_fee_percent as percent', 'marketplace_configs.admin_fee_fixed as fixed']);

            $rows = [];

            if ($cfg && (float) $cfg->percent > 0) {
                $rows[] = ['label' => 'Potongan marketplace', 'percent' => (float) $cfg->percent, 'fixed' => 0, 'include_accounting' => true];
            }
            if ($cfg && (float) $cfg->fixed > 0) {
                $rows[] = ['label' => 'Proses pesanan', 'percent' => 0, 'fixed' => (float) $cfg->fixed, 'include_accounting' => true];
            }
            if ($key === 'shopee') {
                $rows[] = ['label' => 'Premi pengembalian', 'percent' => 0, 'fixed' => 350, 'include_accounting' => false];
            }
            $rows[] = ['label' => 'Biaya Jubelio per pesanan', 'percent' => 0, 'fixed' => 250, 'include_accounting' => false];

            foreach (array_values($rows) as $i => $row) {
                DB::table('price_channel_fee_components')->insert($row + [
                    'channel'    => $key,
                    'sort_order' => $i + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_prices');
        Schema::dropIfExists('price_channel_fee_components');
    }
};
