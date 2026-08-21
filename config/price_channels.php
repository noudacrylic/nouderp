<?php

/**
 * Kanal penjualan untuk Analisa ▸ Harga Produk.
 *
 * Satu "kanal" bukan selalu satu toko: TikTok & Tokopedia kini satu marketplace (satu
 * dompet, satu gudang harga), jadi keduanya digabung dalam satu kanal dengan dua store_id
 * — harga yang dikirim mendarat di dua-duanya sekaligus supaya tidak ada toko yang
 * tertinggal di harga dasar.
 *
 * `customers` dicocokkan ke nama customer marketplace di ERP (case-insensitive). Dari
 * customer itulah store_id Jubelio dan potongan versi akuntansi (MarketplaceConfig)
 * ditemukan. Kanal tanpa customer (Website) tidak pernah menyentuh Jubelio.
 */
return [

    'website' => [
        'label'     => 'Website',
        'kind'      => 'internal',
        'customers' => [],
        'affiliate' => false,
        'note'      => 'Harga toko sendiri — dipakai web storefront sekaligus ERP. Tidak lewat Jubelio, jadi tidak kena biaya per pesanan.',
    ],

    'shopee' => [
        'label'     => 'Shopee',
        'kind'      => 'marketplace',
        'customers' => ['Shopee'],
        'affiliate' => true,
    ],

    'tiktok_tokopedia' => [
        'label'     => 'TikTok/Tokopedia',
        'kind'      => 'marketplace',
        'customers' => ['Tiktok Shop', 'TikTok Shop', 'Tokopedia'],
        'affiliate' => true,
        'note'      => 'Satu kanal, dua toko. Potongan yang dipakai yang tertinggi di antara keduanya supaya angka untungnya konservatif; harga dikirim ke dua store_id sekaligus.',
    ],

    'lazada' => [
        'label'     => 'Lazada',
        'kind'      => 'marketplace',
        'customers' => ['Lazada'],
        'affiliate' => false,
    ],

];
