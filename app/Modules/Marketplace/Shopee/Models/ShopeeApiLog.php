<?php

namespace App\Modules\Marketplace\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jejak satu panggilan API Shopee — tanpa token, kunci, maupun isi respons.
 * Baris method PUSH = Live Push masuk; `message` memuat isinya (terpotong) untuk dipelajari.
 */
class ShopeeApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'method', 'path', 'http_status', 'error', 'message', 'request_id', 'durasi_ms'];

    public function berhasil(): bool
    {
        return $this->http_status >= 200 && $this->http_status < 300 && !$this->error;
    }
}
