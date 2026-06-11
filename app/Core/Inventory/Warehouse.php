<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'location',
        'address',
        'city',
        'province',
        'postal_code',
        'biteship_area_id',
        'kiriminaja_area_id',
        'latitude',
        'longitude',
        'contact_name',
        'contact_phone',
        'is_sellable',
        'is_active'
    ];

    public function stocks()
    {
        return $this->hasMany(ProductStock::class, 'warehouse_id');
    }

    /**
     * Apakah gudang ini siap dijadikan asal pengiriman (punya area_id atau kode pos).
     */
    public function isShippingReady(): bool
    {
        return !empty($this->biteship_area_id) || !empty($this->postal_code);
    }

    /**
     * Payload origin untuk ShippingManager::rates(). Utamakan area_id (lebih akurat),
     * fallback ke kode pos.
     */
    public function shippingOriginPayload(): array
    {
        return array_filter([
            'origin_area_id'      => $this->biteship_area_id ?: null,
            'origin_kiriminaja_id' => $this->kiriminaja_area_id ?: null,
            'origin_postal_code' => $this->postal_code ?: null,
            // Koordinat asal — wajib agar kurir instant (Grab/GoSend/Lalamove) muncul.
            'origin_latitude'    => $this->latitude !== null ? (float) $this->latitude : null,
            'origin_longitude'   => $this->longitude !== null ? (float) $this->longitude : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Ringkasan alamat satu baris untuk ditampilkan di UI. */
    public function fullAddress(): string
    {
        return collect([$this->address, $this->city, $this->province, $this->postal_code])
            ->filter()
            ->implode(', ');
    }

    /**
     * ID gudang default untuk preselect di form.
     * Prioritas: gudang bernama "Utama" (case-insensitive) → gudang aktif pertama → null.
     */
    public static function defaultId(): ?int
    {
        $utama = static::whereRaw('LOWER(name) = ?', ['utama'])->value('id');
        if ($utama) return (int) $utama;

        return static::orderBy('id')->value('id');
    }
}
