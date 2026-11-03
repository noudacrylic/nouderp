<?php

namespace App\Modules\KaryawanVirtual\Controllers;

use App\Models\User;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan Manajer Admin & Asisten Owner: anggota meja masing-masing dan, per skill, meja
 * tujuan task (skill Asisten Owner selalu Meja Owner), bobot urutan kerja, dan tenggat.
 * Nyala/mati skill = mode di kartu skill. Super admin saja — menugaskan orang adalah keputusan pemilik.
 */
class PengaturanAdminController extends Controller
{
    public function __construct(private KaryawanVirtualService $kv, private MejaKerjaService $meja) {}

    public function show(Request $request, string $kode)
    {
        $cfg = $this->karyawan($request, $kode);
        $skills = collect($this->skillKaryawan($kode))->map(fn ($s) => [
            'skill' => $s,
            'atur'  => $this->meja->pengaturan($s),
            'mode'  => $this->kv->mode(MejaKerjaService::kunciKv($s)),
        ]);

        return view('erp.karyawan-virtual.pengaturan-admin', [
            'kode'      => $kode,
            'karyawan'  => $cfg,
            'skills'    => $skills,
            'labelMode' => KaryawanVirtualService::LABEL_MODE,
            'mejaDiatur' => array_intersect_key(MejaKerjaPeran::PERAN, array_flip($this->mejaKaryawan($kode))),
            'peran'     => MejaKerjaPeran::with('user:id,name')->orderBy('urutan')->get()->groupBy('peran'),
            'users'     => $this->calonAnggota(),
        ]);
    }

    /**
     * Calon anggota meja: super admin/admin, karyawan divisi Admin, user bermenu Meja Kerja, dan
     * anggota yang sudah ada. Akun divisi produksi/packing & akun PWA absensi tidak ditawarkan.
     */
    private function calonAnggota()
    {
        return User::where('is_active', true)->where('is_virtual', false)->where('role', '!=', 'karyawan')
            ->where(fn ($q) => $q->whereIn('role', ['super_admin', 'admin'])
                ->orWhereHas('menuPermissions', fn ($m) => $m->where('menu_key', 'tasks.meja'))
                ->orWhereIn('id', MejaKerjaPeran::select('user_id'))
                ->orWhereIn('karyawan_id', DB::table('sdm_karyawan as k')->join('production_departments as d', 'd.id', '=', 'k.department_id')
                    ->where('d.name', 'Admin')->select('k.id')))
            ->orderBy('name')->get(['id', 'name', 'role']);
    }

    public function simpan(Request $request, string $kode)
    {
        $cfg = $this->karyawan($request, $kode);
        $mejaDiatur = $this->mejaKaryawan($kode);
        $data = $request->validate([
            'peran'             => 'array',
            'peran.*'           => 'array',
            'peran.*.*'         => 'nullable|integer|exists:users,id',
            'skill'             => 'array',
            'skill.*.peran'     => 'required|in:' . implode(',', $mejaDiatur),
            'skill.*.bobot'     => 'required|integer|min:1|max:10',
            'skill.*.sla_menit' => 'required|integer|min:5|max:10080',
        ]);

        DB::transaction(function () use ($data, $kode, $mejaDiatur) {
            // Hanya meja karyawan ini: anggota meja karyawan lain tidak ada di form-nya.
            foreach ($mejaDiatur as $p) {
                MejaKerjaPeran::where('peran', $p)->delete();
                foreach (array_values(array_unique(array_filter($data['peran'][$p] ?? []))) as $i => $uid) {
                    MejaKerjaPeran::create(['peran' => $p, 'user_id' => $uid, 'urutan' => $i + 1]);
                }
            }
            foreach ($this->skillKaryawan($kode) as $key => $s) {
                if ($isi = $data['skill'][$key] ?? null) $this->meja->pengaturan($s)->update($isi);
            }
        });

        return redirect()->route('karyawan-virtual.admin.pengaturan', $kode)->with('success', "Pengaturan {$cfg['nama']} disimpan.");
    }

    private function karyawan(Request $request, string $kode): array
    {
        abort_unless(in_array($kode, [MejaKerjaService::KARYAWAN, MejaKerjaService::KARYAWAN_OWNER], true), 404);
        abort_unless($request->user()?->role === 'super_admin', 403, 'Hanya super admin yang boleh mengatur karyawan virtual ini.');
        return $this->kv->karyawan($kode);
    }

    /** @return array<string, \App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin> */
    private function skillKaryawan(string $kode): array
    {
        return array_filter($this->meja->skills(), fn ($s) => ($s instanceof SkillOwner) === ($kode === MejaKerjaService::KARYAWAN_OWNER));
    }

    /** @return string[] Meja Owner milik Asisten Owner; sisanya milik Manajer Admin. */
    private function mejaKaryawan(string $kode): array
    {
        $owner = $kode === MejaKerjaService::KARYAWAN_OWNER;
        return array_values(array_filter(array_keys(MejaKerjaPeran::PERAN), fn ($p) => ($p === 'owner') === $owner));
    }
}
