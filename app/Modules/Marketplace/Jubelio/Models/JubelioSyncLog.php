<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Jejak per-kejadian sinkron Jubelio. Dipakai service push stok/harga & sinkron
 * pesanan untuk mencatat hasil tiap aksi (success/failed/skipped).
 *
 * record() SENGAJA menelan exception: gagal mencatat log tidak boleh menggagalkan
 * proses sinkron yang sebenarnya.
 */
class JubelioSyncLog extends Model
{
    public const TYPE_ORDER = 'order';
    public const TYPE_STOCK = 'stock';
    public const TYPE_PRICE = 'price';

    public const OK   = 'success';
    public const FAIL = 'failed';
    public const SKIP = 'skipped';

    protected $fillable = [
        'type', 'status', 'title', 'reference', 'message', 'meta',
        'product_id', 'jubelio_salesorder_id',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * Catat satu kejadian sinkron. Tidak pernah melempar exception ke pemanggil.
     *
     * @param array{reference?:string|null,message?:string|null,meta?:array|null,product_id?:int|null,jubelio_salesorder_id?:int|null} $opts
     */
    public static function record(string $type, string $status, string $title, array $opts = []): void
    {
        try {
            static::create([
                'type'                  => $type,
                'status'                => $status,
                'title'                 => mb_substr($title, 0, 255),
                'reference'             => $opts['reference'] ?? null,
                'message'               => $opts['message'] ?? null,
                'meta'                  => $opts['meta'] ?? null,
                'product_id'            => $opts['product_id'] ?? null,
                'jubelio_salesorder_id' => $opts['jubelio_salesorder_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('JubelioSyncLog gagal mencatat', ['error' => $e->getMessage()]);
        }
    }

    /* ───────── Pembantu tampilan ───────── */

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::OK   => 'bg-emerald-100 text-emerald-700',
            self::FAIL => 'bg-red-100 text-red-700',
            default    => 'bg-gray-100 text-gray-600',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OK   => 'Berhasil',
            self::FAIL => 'Gagal',
            default    => 'Dilewati',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ORDER => 'Pesanan',
            self::TYPE_STOCK => 'Stok',
            self::TYPE_PRICE => 'Harga',
            default          => ucfirst($this->type),
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            self::TYPE_ORDER => 'bg-indigo-100 text-indigo-700',
            self::TYPE_STOCK => 'bg-sky-100 text-sky-700',
            self::TYPE_PRICE => 'bg-amber-100 text-amber-700',
            default          => 'bg-gray-100 text-gray-600',
        };
    }
}
