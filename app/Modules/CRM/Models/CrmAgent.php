<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu agen AI: siapa dia, model apa yang dipakai, hidup atau mati.
 *
 * Yang TIDAK ada di sini: pengetahuannya (tabel sendiri, berversi) dan
 * pagarnya (AturanTetap, di kode). Pembagian itu disengaja — lihat migrasi
 * 2026_09_11_100000 dan AturanTetap untuk alasannya.
 */
class CrmAgent extends Model
{
    public const PENYAMBUTAN = 'penyambutan';
    public const PENJUALAN   = 'penjualan';
    public const CETAK       = 'cetak';

    protected $fillable = [
        'kode', 'nama', 'deskripsi', 'persona', 'model', 'maks_giliran', 'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'maks_giliran' => 'integer',
    ];

    public function pengetahuan(): HasMany
    {
        return $this->hasMany(CrmAgentKnowledge::class, 'agent_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CrmAgentRun::class, 'agent_id');
    }

    /** Versi pengetahuan yang berlaku sekarang; null kalau belum pernah diunggah. */
    public function pengetahuanAktif(): ?CrmAgentKnowledge
    {
        return $this->pengetahuan()->where('is_active', true)->latest('versi')->first();
    }

    public static function kode(string $kode): ?self
    {
        return static::where('kode', $kode)->first();
    }

    /**
     * Model yang dipakai: pilihan di baris ini, kalau kosong ikut bawaan per
     * kode, kalau kodenya tak dikenal pakai cadangan.
     */
    public function modelDipakai(): string
    {
        if (filled($this->model)) {
            return (string) $this->model;
        }

        $peta = (array) config('crm.agen.model_bawaan', []);

        return (string) ($peta[$this->kode] ?? config('crm.agen.model_cadangan', 'claude-haiku-4-5'));
    }

    /**
     * Boleh membalas pelanggan sungguhan?
     *
     * DUA saklar, bukan satu: saklar induk di config dan saklar per agen. Yang
     * pertama menjaga seluruh modul saat ada yang tidak beres; yang kedua
     * menyalakan agen satu per satu setelah masing-masing terbukti. Mode uji di
     * layar tidak melewati method ini sama sekali — ia memang tidak pernah
     * menyentuh pelanggan.
     */
    public function bolehJalan(): bool
    {
        return (bool) config('crm.agen.aktif', false) && $this->is_active;
    }
}
