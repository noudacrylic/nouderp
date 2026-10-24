<?php

namespace App\Modules\KaryawanVirtual\Services;

use App\Models\User;
use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pintu tunggal karyawan virtual: siapa saja karyawannya, mode tiap skill,
 * menjalankan skill sesuai modenya, dan menulis jurnal kerja.
 *
 * Jurnal hanya ditulis bila ada TINDAKAN (dibuat / akan dibuat / gagal) atau
 * skill dijalankan manual — pemeriksaan rutin tiap 30 menit yang semuanya
 * "masih aman" cukup memperbarui hasil terakhir di karyawan_virtual_skills,
 * supaya jurnal tidak tenggelam oleh ratusan baris kosong per hari.
 *
 * Skill dikerjakan SEBAGAI akun user virtual karyawannya (lihat akun()), jadi
 * dokumen yang dibuatnya (created_by) tercatat atas nama "Manager Produksi",
 * bukan kosong atau atas nama orang yang kebetulan menekan tombol.
 */
class KaryawanVirtualService
{
    public const PEMICU_JADWAL    = 'jadwal';
    public const PEMICU_MANUAL    = 'manual';
    public const PEMICU_PERISTIWA = 'peristiwa';
    /** Usulan disetujui/ditolak manusia — lihat UsulanService. */
    public const PEMICU_PERSETUJUAN = 'persetujuan';

    public const LABEL_MODE = [
        Skill::NONAKTIF    => 'Nonaktif',
        Skill::BAYANGAN    => 'Bayangan',
        Skill::PERSETUJUAN => 'Minta persetujuan',
        Skill::OTOMATIS    => 'Otomatis',
    ];

    /** @var array<string, User> */
    private array $akunCache = [];

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
        // Keluar dari mode persetujuan → usulan yang masih menunggu tak boleh lagi disetujui:
        // menyetujuinya berarti bertindak di luar mode yang dipilih (mis. Nonaktif).
        if ($mode !== Skill::PERSETUJUAN && $this->mode($skillKey) === Skill::PERSETUJUAN) {
            app(UsulanService::class)->batalkanSemua($skillKey,
                'Mode skill diganti ke ' . (self::LABEL_MODE[$mode] ?? $mode) . '.');
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
     * Mode persetujuan: diperiksa seperti bayangan (tidak bertindak), lalu yang akan
     * dikerjakan diajukan sebagai usulan — dikerjakan nanti setelah disetujui.
     *
     * @return array{mode: string, dijalankan: bool, hasil: array, usulan: array, rincian: array, jurnal: ?KaryawanVirtualJurnal}
     */
    public function jalankan(string $skillKey, string $pemicu, ?string $keterangan = null, ?int $userId = null): array
    {
        $mode = $this->mode($skillKey);

        if ($mode === Skill::NONAKTIF) {
            return ['mode' => $mode, 'dijalankan' => false, 'hasil' => [], 'usulan' => [], 'rincian' => [], 'jurnal' => null];
        }

        $skill    = $this->skill($skillKey);
        $simulasi = $mode !== Skill::OTOMATIS;
        $hasil    = $this->sebagai($this->pemilik($skillKey), fn () => $skill->jalankan($simulasi));

        $usulan = [];
        if ($mode === Skill::PERSETUJUAN && $skill instanceof BisaDiusulkan) {
            $usulan = app(UsulanService::class)->ajukan($skillKey, $skill->usulan($hasil));
        }

        $catatan = $this->catat($skillKey, $hasil, $pemicu, $keterangan, $userId, $mode, $usulan);

        return ['mode' => $mode, 'dijalankan' => true, 'hasil' => $hasil, 'usulan' => $usulan] + $catatan;
    }

    /**
     * Akun user milik karyawan virtual — dibuat saat pertama dibutuhkan. Tidak bisa
     * login (is_virtual ditolak AuthController, sandi acak tak tersimpan di mana pun),
     * tak tampil di User & Akses, tanpa karyawan_id jadi tak masuk payroll.
     */
    public function akun(string $kode): User
    {
        if (isset($this->akunCache[$kode])) return $this->akunCache[$kode];

        $cfg  = $this->karyawan($kode) ?? throw new \InvalidArgumentException("Karyawan virtual tidak terdaftar: {$kode}");
        $akun = User::where('username', 'kv.' . $kode)->first();
        if (!$akun) {
            $akun = new User();
            $akun->forceFill([
                'username'   => 'kv.' . $kode,
                'name'       => $cfg['nama'],
                'password'   => Str::random(64),
                'role'       => 'user',
                'is_active'  => true,
                'is_virtual' => true,
            ])->save();
        }

        return $this->akunCache[$kode] = $akun;
    }

    /**
     * Kerjakan $kerja sebagai akun user virtual $kode, lalu kembalikan user yang
     * login sebelumnya (orang yang menekan tombol, atau tidak ada saat cron).
     * setUser, bukan login: sesi orang itu tidak tersentuh.
     */
    public function sebagai(string $kode, callable $kerja): mixed
    {
        $sebelumnya = Auth::user();
        Auth::setUser($this->akun($kode));
        try {
            return $kerja();
        } finally {
            $sebelumnya ? Auth::setUser($sebelumnya) : Auth::forgetUser();
        }
    }

    /**
     * Catat hasil kerja skill. Dipanggil jalankan(), dan langsung oleh pemicu
     * peristiwa yang memanggil service modul sendiri (mis. DP diposting).
     * Tidak pernah melempar galat — gagal mencatat tidak boleh menggagalkan kerjanya.
     *
     * $usulan = hasil UsulanService::ajukan (indeks baris hasil => [id, baru]): baris itu
     * ditandai "diusulkan" (baru — dihitung tindakan) atau "menunggu" (usulan lama yang
     * belum diputus — tidak dihitung, supaya tiap 30 menit tak menulis jurnal yang sama).
     *
     * @return array{rincian: array, jurnal: ?KaryawanVirtualJurnal}
     */
    public function catat(string $skillKey, array $hasil, string $pemicu, ?string $keterangan = null, ?int $userId = null, ?string $mode = null, array $usulan = []): array
    {
        try {
            $skill   = $this->skill($skillKey);
            $rincian = $skill->rincian($hasil);
            foreach ($usulan as $i => $u) {
                if (!isset($rincian[$i])) continue;
                $rincian[$i]['status']    = $u['baru'] ? Skill::DIUSULKAN : Skill::MENUNGGU;
                $rincian[$i]['alasan']    = trim(($rincian[$i]['alasan'] ?? '') . ' ' . ($u['baru']
                    ? "Diajukan sebagai usulan #{$u['id']}."
                    : "Usulan #{$u['id']} masih menunggu persetujuan."));
                $rincian[$i]['usulan_id'] = $u['id'];
            }
            // Tindakan di atas, yang dilewati di bawah (urutan asli dipertahankan di tiap kelompok).
            $rincian  = collect($rincian)
                ->sortBy(fn ($r, $i) => [$r['status'] === Skill::DILEWATI ? 1 : 0, $i])
                ->values()->all();
            $mode   ??= $this->mode($skillKey);
            $tindakan = count(array_filter($rincian, fn ($r) => !in_array($r['status'], [Skill::DILEWATI, Skill::MENUNGGU], true)));
            $ringkasan = $this->ringkas($rincian, $skill->satuan());

            // Pemeriksaan menyeluruh (bukan satu peristiwa / satu usulan) → simpan sebagai hasil terakhir.
            if (!in_array($pemicu, [self::PEMICU_PERISTIWA, self::PEMICU_PERSETUJUAN], true)) {
                $this->state($skillKey)->update([
                    'terakhir_jalan_at'  => now(),
                    'ringkasan_terakhir' => mb_substr($ringkasan, 0, 255),
                    'rincian_terakhir'   => $rincian,
                ]);
            }

            $jurnal = null;
            if ($tindakan > 0 || in_array($pemicu, [self::PEMICU_MANUAL, self::PEMICU_PERSETUJUAN], true)) {
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
            ($n[Skill::DIUSULKAN] ?? 0)   ? $n[Skill::DIUSULKAN] . ' diusulkan' : null,
            ($n[Skill::MENUNGGU] ?? 0)    ? $n[Skill::MENUNGGU] . ' menunggu persetujuan' : null,
            ($n[Skill::GAGAL] ?? 0)       ? $n[Skill::GAGAL] . ' gagal' : null,
            ($n[Skill::DILEWATI] ?? 0)    ? $n[Skill::DILEWATI] . ' dilewati' : null,
        ]);

        return count($rincian) . " {$satuan} diperiksa" . ($bagian ? ': ' . implode(', ', $bagian) : '') . '.';
    }
}
