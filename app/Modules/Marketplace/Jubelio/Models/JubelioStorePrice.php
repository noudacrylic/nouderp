<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Harga yang sedang dipegang Jubelio untuk satu produk di satu toko.
 *
 * Ini REKAMAN, bukan kebenaran: umurnya ikut ditampilkan supaya tidak ada yang membacanya
 * sebagai harga hari ini padahal ditarik seminggu lalu. Lihat migrasinya untuk alasan
 * kenapa tabel ini berdiri sendiri dan tidak ikut sidik jari AnalysisCache.
 *
 * Isinya datang dari dua arah: sapuan `jubelio:tarik-harga` (dan tombolnya) untuk seluruh
 * katalog, serta pengecekan balik sesudah tombol Kirim — yang terakhir menjaga baris produk
 * yang baru saja diubah tetap segar tanpa perlu menyapu apa pun.
 */
class JubelioStorePrice extends Model
{
    protected $table = 'jubelio_store_prices';

    protected $fillable = ['product_id', 'store_id', 'price', 'fetched_at', 'note'];

    protected $casts = [
        'store_id'   => 'integer',
        'price'      => 'float',
        'fetched_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Rangkuman per produk untuk sederet toko — bentuk yang langsung dipakai tabel harga.
     *
     * Satu kanal bisa punya beberapa toko. Yang dilaporkan sengaja bukan rata-ratanya:
     * kalau dua toko berharga beda, itu bukan detail yang boleh dilebur, melainkan justru
     * temuan yang harus terbaca ("Tokopedia 250rb, TikTok 265rb").
     *
     * @param  array<int,int> $storeIds
     * @return Collection<int,array{price:?float,fetched_at:?\Illuminate\Support\Carbon,seragam:bool,per_toko:array<int,?float>,note:?string}>
     */
    public static function ringkasUntuk(array $storeIds): Collection
    {
        $storeIds = array_values(array_filter(array_map('intval', $storeIds)));

        if (empty($storeIds)) {
            return collect();
        }

        return static::whereIn('store_id', $storeIds)->get()
            ->groupBy('product_id')
            ->map(function (Collection $baris) {
                $berharga = $baris->filter(fn ($r) => $r->price !== null);
                $nilai    = $berharga->pluck('price')->map(fn ($v) => round((float) $v))->unique();

                return [
                    // Satu angka hanya bila semua toko sepakat; kalau tidak, biarkan kosong
                    // supaya yang terbaca adalah rinciannya, bukan angka yang menyesatkan.
                    'price'      => $nilai->count() === 1 ? (float) $berharga->first()->price : null,
                    'seragam'    => $nilai->count() <= 1,
                    'per_toko'   => $baris->mapWithKeys(fn ($r) => [$r->store_id => $r->price])->all(),
                    // Yang tertua: rekaman hanya sesegar bagian paling basinya.
                    'fetched_at' => $baris->min('fetched_at'),
                    'note'       => $baris->firstWhere(fn ($r) => filled($r->note))?->note,
                ];
            });
    }
}
