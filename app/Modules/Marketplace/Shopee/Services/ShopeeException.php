<?php

namespace App\Modules\Marketplace\Shopee\Services;

/** Galat dari Shopee Open Platform ('error' tak kosong) atau koneksi gagal. */
class ShopeeException extends \RuntimeException
{
    public function __construct(public readonly string $kode, string $pesan, public readonly ?string $requestId = null)
    {
        parent::__construct($pesan !== '' ? "{$kode}: {$pesan}" : $kode);
    }
}
