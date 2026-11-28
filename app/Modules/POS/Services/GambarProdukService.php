<?php

namespace App\Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gambar produk untuk kartu packing, diambil dari produk WEB (ERP tak menyimpan gambar sendiri):
 * varian web yang tertaut ke SKU ERP → foto varian; tanpa foto varian → foto utama galeri produk
 * webnya. Draf pun dipakai (cukup diupload, belum harus tayang).
 *
 * Gunanya: varian yang bedanya cuma di desain (Akrilik Sign Kiri vs Kanan) tak bisa dibedakan dari
 * nama/ukuran — packing harus mencocokkan gambarnya. SKU berawalan WAJIB_PREFIX tanpa gambar
 * diberi peringatan di kartu.
 *
 * Katalog web kecil (ratusan baris) → peta dimuat SEKALI per permintaan (scoped), bukan per kartu.
 */
class GambarProdukService
{
    /** Awalan SKU yang wajib bergambar di kartu packing. */
    public const WAJIB_PREFIX = ['AS-'];

    /** @var array<int, string>|null product_id → url */
    private ?array $peta = null;

    public function url(?int $productId): ?string
    {
        return $productId ? ($this->peta()[$productId] ?? null) : null;
    }

    public static function wajib(?string $sku): bool
    {
        foreach (self::WAJIB_PREFIX as $awalan) {
            if (str_starts_with(strtoupper((string) $sku), $awalan)) return true;
        }
        return false;
    }

    /** @return array<int, string> */
    private function peta(): array
    {
        if ($this->peta !== null) return $this->peta;
        if (!Schema::hasTable('store_product_variants')) return $this->peta = [];

        $media = DB::table('store_product_media')
            ->whereNull('deleted_at')->where('group', 'gallery')->where('kind', 'image')
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'store_product_id', 'url']);
        $perId    = $media->keyBy('id');
        $utamaPer = $media->unique('store_product_id')->keyBy('store_product_id');

        // product_id unik di store_product_variants → satu SKU ERP = satu varian web.
        $varian = DB::table('store_product_variants')->whereNotNull('product_id')
            ->get(['product_id', 'store_product_id', 'image_media_id']);

        $peta = [];
        foreach ($varian as $v) {
            $url = $perId[$v->image_media_id]->url ?? $utamaPer[$v->store_product_id]->url ?? null;
            if ($url) $peta[(int) $v->product_id] = $url;
        }

        return $this->peta = $peta;
    }
}
