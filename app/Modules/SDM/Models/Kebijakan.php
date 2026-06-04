<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Kebijakan extends Model
{
    protected $table = 'sdm_kebijakan_setting';

    protected $fillable = ['key', 'value', 'label', 'deskripsi'];

    protected $casts = [
        'value' => 'array',
    ];

    public const K_TUNJANGAN_HARIAN  = 'tunjangan_harian_per_status';
    public const K_BONUS_KEHADIRAN   = 'bonus_kehadiran';
    public const K_TUNJANGAN_SP      = 'tunjangan_bulanan_sp';
    public const K_THRESHOLD_ABSENSI = 'threshold_absensi';

    public const STATUS_LABELS = [
        'hadir'         => 'Hadir tepat waktu',
        'terlambat'     => 'Terlambat',
        'pulang_awal'   => 'Pulang awal',
        'setengah_hari' => 'Setengah hari',
        'cuti'          => 'Cuti',
        'sakit'         => 'Sakit',
        'libur'         => 'Libur',
        'tidak_hadir'   => 'Tidak hadir',
        'lembur'        => 'Lembur',
    ];

    public const SP_LEVELS = ['SP0', 'SP1', 'SP2', 'SP3'];

    public static function get(string $key, $default = null)
    {
        return Cache::remember("kebijakan:{$key}", 300, function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    public static function set(string $key, array $value): void
    {
        $row = static::where('key', $key)->first();
        if ($row) {
            $row->update(['value' => $value]);
        }
        Cache::forget("kebijakan:{$key}");
    }

    public static function tunjanganHarianAllowed(string $status): bool
    {
        $cfg = static::get(self::K_TUNJANGAN_HARIAN, []);
        return (bool) ($cfg[$status] ?? false);
    }

    public static function bonusKehadiran(): array
    {
        return static::get(self::K_BONUS_KEHADIRAN, [
            'enabled' => false, 'threshold_time' => '08:00', 'amount' => 0,
        ]);
    }

    public static function tunjanganSp(string $sanksi): float
    {
        $cfg = static::get(self::K_TUNJANGAN_SP, []);
        return (float) ($cfg[$sanksi] ?? 0);
    }

    public static function threshold(string $name, string $default = '00:00'): string
    {
        $cfg = static::get(self::K_THRESHOLD_ABSENSI, []);
        return (string) ($cfg[$name] ?? $default);
    }
}
