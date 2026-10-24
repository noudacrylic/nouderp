<?php

namespace App\Modules\KaryawanVirtual\Controllers;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Menu Karyawan Virtual. Bukan menu di config/menu_permissions.php: tiap
 * karyawan virtual dibuka oleh siapa pun yang punya akses menu divisinya
 * (config karyawan_virtual.*.akses) — kepala produksi memantau Manager
 * Produksi tanpa perlu akses SDM/gaji. Mengganti mode skill: super_admin.
 */
class KaryawanVirtualController extends Controller
{
    public function __construct(private KaryawanVirtualService $kv) {}

    public function index()
    {
        $karyawan = collect($this->kv->semua())
            ->filter(fn ($cfg, $kode) => $this->kv->bolehLihat($kode))
            ->map(function ($cfg, $kode) {
                $skills = collect(array_keys($cfg['skills']))->map(fn ($key) => [
                    'key'   => $key,
                    'label' => $this->kv->skill($key)->label(),
                    'mode'  => $this->kv->mode($key),
                ]);
                return $cfg + [
                    'kode'          => $kode,
                    'daftar_skill'  => $skills,
                    'jurnal_terbaru' => KaryawanVirtualJurnal::where('karyawan', $kode)->latest('id')->first(),
                ];
            });

        abort_if($karyawan->isEmpty(), 403, 'Anda tidak punya akses ke karyawan virtual mana pun.');

        return view('erp.karyawan-virtual.index', compact('karyawan'));
    }

    public function show(Request $request, string $kode)
    {
        $cfg = $this->karyawan($kode);

        $skills = collect(array_keys($cfg['skills']))->map(function ($key) {
            $skill = $this->kv->skill($key);
            return [
                'key'      => $key,
                'skill'    => $skill,
                'mode'     => $this->kv->mode($key),
                'state'    => $this->kv->state($key)->load('pengubahMode:id,name'),
            ];
        });

        $jurnal = KaryawanVirtualJurnal::with('user:id,name')
            ->where('karyawan', $kode)
            ->when($request->filled('skill'), fn ($q) => $q->where('skill', $request->string('skill')))
            ->when($request->input('tampil') !== 'semua', fn ($q) => $q->where('jumlah_tindakan', '>', 0))
            ->latest('id')
            ->paginate(per_page_size())
            ->withQueryString();

        $labelSkill = $skills->mapWithKeys(fn ($s) => [$s['key'] => $s['skill']->label()]);

        return view('erp.karyawan-virtual.show', [
            'kode'       => $kode,
            'karyawan'   => $cfg,
            'skills'     => $skills,
            'jurnal'     => $jurnal,
            'labelSkill' => $labelSkill,
            'labelMode'  => KaryawanVirtualService::LABEL_MODE,
            'bolehAtur'  => $request->user()?->role === 'super_admin',
        ]);
    }

    public function ubahMode(Request $request, string $kode, string $skill)
    {
        $this->karyawan($kode, $skill);
        abort_unless($request->user()?->role === 'super_admin', 403, 'Hanya super admin yang boleh mengganti mode skill.');
        $request->validate(['mode' => 'required|string']);

        try {
            $this->kv->ubahMode($skill, $request->input('mode'), $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = KaryawanVirtualService::LABEL_MODE[$request->input('mode')] ?? $request->input('mode');
        return back()->with('success', "Mode \"{$this->kv->skill($skill)->label()}\" sekarang: {$label}.");
    }

    /** "Jalankan sekarang" — sama dengan jadwal, tapi tercatat sebagai manual oleh user ini. */
    public function jalankan(Request $request, string $kode, string $skill)
    {
        $this->karyawan($kode, $skill);

        $hasil = $this->kv->jalankan($skill, KaryawanVirtualService::PEMICU_MANUAL,
            'Tombol "Jalankan sekarang" (halaman Karyawan Virtual)', $request->user()?->id);

        if (!$hasil['dijalankan']) {
            return back()->with('error', 'Skill ini sedang nonaktif.');
        }

        $ringkasan = $this->kv->ringkas($hasil['rincian'], $this->kv->skill($skill)->satuan());
        $bayangan  = $hasil['mode'] !== Skill::OTOMATIS ? ' (mode Bayangan — tidak ada yang dibuat)' : '';

        return back()->with('success', $ringkasan . $bayangan);
    }

    private function karyawan(string $kode, ?string $skill = null): array
    {
        $cfg = $this->kv->karyawan($kode);
        abort_if($cfg === null, 404);
        abort_unless($this->kv->bolehLihat($kode), 403, 'Anda tidak punya akses ke karyawan virtual ini.');
        abort_if($skill !== null && !isset($cfg['skills'][$skill]), 404);
        return $cfg;
    }
}
