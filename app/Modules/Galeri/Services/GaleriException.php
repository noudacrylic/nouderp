<?php

namespace App\Modules\Galeri\Services;

/**
 * Galat yang pesannya layak ditunjukkan ke admin apa adanya.
 * `diterima` hanya diisi untuk potongan unggahan yang tidak berurutan:
 * panjang yang sudah tersimpan, supaya HP melanjutkan dari sana.
 */
class GaleriException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $diterima = null)
    {
        parent::__construct($message);
    }
}
