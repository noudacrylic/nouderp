<?php

namespace App\Modules\KaryawanVirtual\Services;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use Illuminate\Support\Facades\Log;

/**
 * Pintu tunggal karyawan virtual: siapa saja karyawannya, mode tiap skill,
 * menjalankan skill sesuai modenya, dan menulis jurnal kerja.
 *
 * Jurnal hanya ditulis bila ada TINDAKAN (dibuat / akan dibuat / gagal) atau
 * skill dijalankan manual — pemeriksaan rutin tiap 30 menit yang semuanya
 * "masih aman" cukup memperbarui hasil terakhir di karyawan_virtual_skills,
 * supaya jurnal tidak tenggelam oleh ratusan baris kosong per hari.
 */
class KaryawanVirtualService
{
    public const PEMICU_JADWAL    = 'jadwal';
    public const PEMICU_MANUAL    = 'manual';
    public const PEMICU_PERISTIWA = 'peristiwa';

    public const LABEL_MODE = [
        Skill::NONAKTIF    => 'Nonaktif',
        Skill::BAYANGAN    => 'Bayangan',
        Skill::PERSETUJUAN => 'Minta persetujuan',
        Skill::OTOMATIS    => 'Otomatis',
    ];

    /** @return array<string, array> kode karyawan => konfigurasi */
    public function semua(): array
    {
        return config('karyawan_virtual', []);
    }

    public function karyawan(string $kode): ?array
    {
        return $this->semua()[$kode] ?? null;
    }

    /** Karyawan pemilik sebuah skill. */
    public function pemilik(string $skillKey): string
    {
        foreach ($this->semua() as $kode => $cfg) {
            if (isset($cfg['skills'][$skillKey])) return $kode;
        }
        throw new \InvalidArgumentException("Skill tidak terdaftar: {$skillKey}");
    }

    public function skill(string $skillKey): Skill
    {
        return app($this->semua()[$this->pemilik($skillKey)]['skills'][$skillKey]);
    }

    /** Boleh membuka halaman karyawan ini? = punya akses menu divisinya. */
    public function bolehLihat(string $kode): bool
    {
        $cfg = $this->karyawan($kode);
        return $cfg !== null && user_can_access($cfg['akses']);
    }

    public function state(string $skillKey): KaryawanVirtualSkill
    {
        return KaryawanVirtualSkill::firstOrCreate(
            ['skill' => $skillKey],
            ['mode' => $this->skill($skillKey)->modeBawaan()],
        );
    }

    public function mode(string $skillKey): string
    {
        $skill = $this->skill($skillKey);
        $mode  = $this->state($skillKey)->mode;
        // Mode tersimpan yang tidak (lagi) diizinkan skill → kembali ke bawaan.
        return in_array($mode, $skill->modeTersedia(), true) ? $mode : $skill->modeBawaan();
    }

    public function ubahMode(string $skillKey, string $mode, ?int $userId): void
    {
        if (!in_array($mode, $this->skill($skillKey)->modeTersedia(), true)) {
            throw new \DomainException('Mode ini tidak tersedia untuk skill tersebut.');
        }
        $this->state($skillKey)->update([
            'mode'             => $mode,
            'mode_diubah_oleh' => $userId,
            'mode_diubah_at'   => now(),
        ]);
    }

    /**
     * Jalankan skill sesuai modenya.
     *
     * @return array{mode: string, dijalankan: bool, hasil: array, rincian: array, jurnal: ?KaryawanVirtualJurnal}
     */
    public function jalankan(string $skillKey, string $pemicu, ?string $keterangan = null, ?int $userId = null): array
    {
        $mode = $this->mode($skillKey);

        if ($mode === Skill::NONAKTIF) {
            return ['mode' => $mode, 'dijalankan' => false, 'hasil' => [], 'rincian' => [], 'jurnal' => null];
        }

        // Persetujuan belum ada alurnya (tahap 2) → perlakukan sebagai bayangan: aman, tidak bertindak.
        $simulasi = $mode !== Skill::OTOMATIS;
        $hasil    = $this->skill($skillKey)->jalankan($simulasi);
        $catatan  = $this->catat($skillKey, $hasil, $pemicu, $keterangan, $userId, $mode);

        return ['mode' => $mode, 'dijalankan' => true, 'hasil' => $hasil] + $catatan;
    }

    /**
     * Catat hasil kerja skill. Dipanggil jalankan(), dan langsung oleh pemicu
     * peristiwa yang memanggil service modul sendiri (mis. DP diposting).
     * Tidak pernah melempar galat — gagal mencatat tidak boleh menggagalkan kerjanya.
     *
     * @return array{rincian: array, jurnal: ?KaryawanVirtualJurnal}
     */
    public function catat(string $skillKey, array $hasil, string $pemicu, ?string $keterangan = null, ?int $userId = null, ?string $mode = null): array
    {
        try {
            $skill    = $this->skill($skillKey);
            // Tindakan di atas, yang dilewati di bawah (urutan asli dipertahankan di tiap kelompok).
            $rincian  = collect($skill->rincian($hasil))
                ->sortBy(fn ($r, $i) => [$r['status'] === Skill::DILEWATI ? 1 : 0, $i])
                ->values()->all();
            $mode   ??= $this->mode($skillKey);
            $tindakan = count(array_filter($rincian, fn ($r) => $r['status'] !== Skill::DILEWATI));
            $ringkasan = $this->ringkas($rincian, $skill->satuan());

            // Pemeriksaan menyeluruh (bukan satu peristiwa) → simpan sebagai hasil terakhir.
            if ($pemicu !== self::PEMICU_PERISTIWA) {
                $this->state($skillKey)->update([
                    'terakhir_jalan_at'  => now(),
                    'ringkasan_terakhir' => mb_substr($ringkasan, 0, 255),
                    'rincian_terakhir'   => $rincian,
                ]);
            }

            $jurnal = null;
            if ($tindakan > 0 || $pemicu === self::PEMICU_MANUAL) {
                $jurnal = KaryawanVirtualJurnal::create([
                    'karyawan'          => $this->pemilik($skillKey),
                    'skill'             => $skillKey,
                    'mode'              => $mode,
                    'pemicu'            => $pemicu,
                    'keterangan_pemicu' => $keterangan !== null ? mb_substr($keterangan, 0, 190) : null,
                    'user_id'           => $userId,
                    'ringkasan'         => mb_substr($ringkasan, 0, 255),
                    'jumlah_tindakan'   => $tindakan,
                    'jumlah_diperiksa'  => count($rincian),
                    // Yang dilewati tetap ikut: "kenapa produk X tidak dibuatkan OP" dijawab di sini.
                    'rincian'           => $rincian,
                ]);
            }

            return ['rincian' => $rincian, 'jurnal' => $jurnal];
        } catch (\Throwable $e) {
            Log::error('[karyawan-virtual] gagal mencatat jurnal', ['skill' => $skillKey, 'error' => $e->getMessage()]);
            return ['rincian' => [], 'jurnal' => null];
        }
    }

    /** "12 BOM diperiksa: 1 OP dibuat, 11 dilewati." */
    public function ringkas(array $rincian, string $satuan): string
    {
        $n = collect($rincian)->countBy('status');
        $bagian = array_filter([
            ($n[Skill::DIBUAT] ?? 0)      ? $n[Skill::DIBUAT] . ' dibuat' : null,
            ($n[Skill::AKAN_DIBUAT] ?? 0) ? $n[Skill::AKAN_DIBUAT] . ' akan dibuat (bayangan)' : null,
            ($n[Skill::GAGAL] ?? 0)       ? $n[Skill::GAGAL] . ' gagal' : null,
            ($n[Skill::DILEWATI] ?? 0)    ? $n[Skill::DILEWATI] . ' dilewati' : null,
        ]);

        return count($rincian) . " {$satuan} diperiksa" . ($bagian ? ': ' . implode(', ', $bagian) : '') . '.';
    }
}
