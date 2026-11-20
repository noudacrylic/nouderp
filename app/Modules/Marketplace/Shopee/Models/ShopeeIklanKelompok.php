<?php

namespace App\Modules\Marketplace\Shopee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok iklan Shopee: di jam ramai semua anggota aktif; di jam sepi hanya `aktif_sepi`
 * iklan yang tayang, bergiliran tiap `rotasi_jam` jam (berbobot ROAS).
 */
class ShopeeIklanKelompok extends Model
{
    protected $table = 'shopee_iklan_kelompok';

    public const MODE = [
        'mati'     => 'Mati',
        'bayangan' => 'Bayangan (hanya mencatat)',
        'otomatis' => 'Otomatis (mengubah iklan di Shopee)',
    ];

    protected $fillable = ['env', 'nama', 'mode', 'jam_ramai', 'aktif_sepi', 'rotasi_jam', 'batas_belanja', 'jadwal_manual', 'status_simulasi', 'dijalankan_at'];

    protected $casts = [
        'jam_ramai'       => 'array',
        'status_simulasi' => 'array',
        'jadwal_manual'   => 'array',
        'aktif_sepi'      => 'integer',
        'rotasi_jam'      => 'integer',
        'batas_belanja'   => 'decimal:2',
        'dijalankan_at'   => 'datetime',
    ];

    public function iklan(): HasMany
    {
        return $this->hasMany(ShopeeIklan::class, 'kelompok_id');
    }

    public function jamRamai(): array
    {
        return array_values(array_map('intval', $this->jam_ramai ?? []));
    }

    /** Jam tayang manual satu iklan, atau null bila iklan itu ikut giliran otomatis. */
    public function jamManual(int $campaignId): ?array
    {
        $m = $this->jadwal_manual ?? [];

        return array_key_exists((string) $campaignId, $m) ? array_values(array_map('intval', $m[(string) $campaignId])) : null;
    }

    /** "06–11, 13–17" dari daftar jam. */
    public function ringkasJamRamai(): string
    {
        $jam = $this->jamRamai();
        sort($jam);
        if (!$jam) {
            return '—';
        }
        $bagian = [];
        $mulai  = $sebelum = $jam[0];
        foreach (array_slice($jam, 1) as $j) {
            if ($j === $sebelum + 1) {
                $sebelum = $j;
                continue;
            }
            $bagian[] = [$mulai, $sebelum];
            $mulai = $sebelum = $j;
        }
        $bagian[] = [$mulai, $sebelum];

        return implode(', ', array_map(fn ($b) => sprintf('%02d', $b[0]) . ($b[1] !== $b[0] ? '–' . sprintf('%02d', $b[1]) : ''), $bagian));
    }

    public function labelMode(): string
    {
        return self::MODE[$this->mode] ?? $this->mode;
    }
}
