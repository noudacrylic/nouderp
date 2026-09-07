<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Label percakapan ("bola di siapa") yang bisa diubah lewat layar Pengaturan.
 *
 * Yang tersimpan di percakapan adalah KODE, bukan namanya. Mengganti nama label
 * karena itu tidak menyentuh satu baris pun di crm_conversations — dan label
 * yang sudah dipakai tidak boleh dihapus, hanya dinonaktifkan, supaya chat lama
 * tidak berubah jadi tanpa label diam-diam.
 */
class CrmLabel extends Model
{
    protected $table = 'crm_labels';

    protected $fillable = ['kode', 'nama', 'warna', 'urutan', 'aktif'];

    protected $casts = [
        'urutan' => 'integer',
        'aktif'  => 'boolean',
    ];

    /** Palet chip. Dipatok di sini supaya kelas Tailwind-nya tertulis utuh. */
    public const WARNA = [
        'orange' => 'bg-orange-100 text-orange-700',
        'blue'   => 'bg-blue-100 text-blue-700',
        'purple' => 'bg-purple-100 text-purple-700',
        'green'  => 'bg-emerald-100 text-emerald-700',
        'red'    => 'bg-red-100 text-red-700',
        'amber'  => 'bg-amber-100 text-amber-800',
        'gray'   => 'bg-gray-100 text-gray-600',
    ];

    public const NAMA_WARNA = [
        'orange' => 'Oranye',
        'blue'   => 'Biru',
        'purple' => 'Ungu',
        'green'  => 'Hijau',
        'red'    => 'Merah',
        'amber'  => 'Kuning',
        'gray'   => 'Abu-abu',
    ];

    /** Cache satu permintaan: daftar ini dibaca berkali-kali per render. */
    private static ?Collection $memo = null;

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('aktif', true);
    }

    /** Semua label (termasuk yang nonaktif), sudah terurut. */
    public static function semua(): Collection
    {
        return self::$memo ??= static::orderBy('urutan')->orderBy('id')->get();
    }

    /** Label yang boleh dipilih di layar. */
    public static function terpakai(): Collection
    {
        return static::semua()->where('aktif', true)->values();
    }

    /** kode => nama, hanya yang aktif. */
    public static function peta(): array
    {
        return static::terpakai()->pluck('nama', 'kode')->all();
    }

    /**
     * Nama untuk satu kode. Label yang sudah dinonaktifkan tetap dikenali —
     * kalau tidak, percakapan lama tampil dengan tulisan mentah 'menunggu_kita'.
     */
    public static function nama(?string $kode): string
    {
        if (! $kode) {
            return '—';
        }

        return static::semua()->firstWhere('kode', $kode)?->nama
            ?? CrmConversation::QUEUE_LABELS[$kode]
            ?? Str::headline($kode);
    }

    public static function kelas(?string $kode): string
    {
        $warna = static::semua()->firstWhere('kode', $kode)?->warna ?? 'gray';

        return self::WARNA[$warna] ?? self::WARNA['gray'];
    }

    public function kelasChip(): string
    {
        return self::WARNA[$this->warna] ?? self::WARNA['gray'];
    }

    /** Kode bawaan untuk chat masuk baru bila label 'menunggu_kita' dihapus. */
    public static function kodeBawaanMasuk(): string
    {
        $ada = static::terpakai();

        return $ada->firstWhere('kode', CrmConversation::QUEUE_KITA)?->kode
            ?? $ada->first()?->kode
            ?? CrmConversation::QUEUE_KITA;
    }

    protected static function booted(): void
    {
        $lupakan = fn () => self::$memo = null;

        static::saved($lupakan);
        static::deleted($lupakan);
    }
}
