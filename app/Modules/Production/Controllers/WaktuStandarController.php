<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Services\PenilaianKinerjaService;
use App\Modules\Production\Services\WaktuStandarService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Layar Waktu Standar: usulan menit/pcs per SKU dari riwayat → disetujui/dikoreksi owner.
 * Khusus owner (role_gate super_admin di menu) karena dasar penilaian & bonus karyawan.
 */
class WaktuStandarController extends Controller
{
    /** Divisi yang sudah punya penilaian waktu standar. */
    private const DIVISI = [2];

    public function __construct(private WaktuStandarService $ws) {}

    /** EnsureMenuAccess meloloskan semua admin; layar ini khusus owner. */
    private function khususOwner(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403, 'Waktu standar hanya untuk owner.');
    }

    /** Saringan layar (divisi, riwayat hari, pelaksana acuan) — sama untuk halaman & baris AJAX. */
    private function saringan(Request $request): array
    {
        $deptId = (int) $request->input('divisi', self::DIVISI[0]);
        abort_unless(in_array($deptId, self::DIVISI, true), 404);
        $hari = max(30, min(365, (int) $request->input('hari', 150)));
        $pelaksana = $request->has('pelaksana')
            ? array_map('intval', (array) $request->input('pelaksana'))
            : $this->ws->pelaksanaLama($deptId)->all();

        return [$deptId, $hari, $pelaksana];
    }

    /** HTML satu SKU (baris + rincian) setelah coret/simpan — diganti di tempat tanpa muat ulang. */
    private function barisJson(Request $request, int $productId, string $pesan, bool $buka)
    {
        [$deptId, $hari, $pelaksana] = $this->saringan($request);
        $u = $this->ws->usulan($deptId, now()->subDays($hari)->startOfDay(), $pelaksana)->get($productId);

        return response()->json([
            'pesan' => $pesan,
            'html'  => $u ? view('erp.production.waktu-standar._sku', ['u' => $u, 'buka' => $buka])->render() : '',
        ]);
    }

    public function index(Request $request)
    {
        $this->khususOwner($request);
        [$deptId, $hari, $pelaksana] = $this->saringan($request);
        $semuaPelaksana = DepartmentExecutor::where('department_id', $deptId)->orderBy('name')->get(['id', 'name', 'is_active', 'created_at']);

        $usulan = $this->ws->usulan($deptId, now()->subDays($hari)->startOfDay(), $pelaksana);

        return view('erp.production.waktu-standar.index', [
            'divisi'         => Department::whereIn('id', self::DIVISI)->get(['id', 'name']),
            'deptId'         => $deptId,
            'hari'           => $hari,
            'semuaPelaksana' => $semuaPelaksana,
            'pelaksana'      => $pelaksana,
            'usulan'         => $usulan,
            'minSampel'      => WaktuStandarService::MIN_SAMPEL,
            'faktor'         => WaktuStandarService::FAKTOR_TAK_WAJAR,
        ]);
    }

    public function simpan(Request $request)
    {
        $this->khususOwner($request);
        $data = $request->validate([
            'divisi'          => 'required|integer|in:' . implode(',', self::DIVISI),
            'berlaku_mulai'   => 'required|date',
            'catatan'         => 'nullable|string|max:255',
            'standar'         => 'array',
            'standar.*.menit' => 'nullable|string|max:20',
            'standar.*.sumber'=> 'nullable|in:median,rata,manual',
            'standar.*.sampel'=> 'nullable|integer',
            'standar.*.usulan'=> 'nullable|string|max:20',
        ]);

        $isi = [];
        foreach ($data['standar'] ?? [] as $pid => $d) {
            $menit = clean_number($d['menit'] ?? '');
            if ($menit <= 0) continue;
            $usulan = isset($d['usulan']) && $d['usulan'] !== '' ? clean_number($d['usulan']) : null;
            $isi[(int) $pid] = [
                'menit'  => $menit,
                // Sama persis dengan usulan (rata-rata sampel wajar) = sumber rata; diketik lain = manual.
                'sumber' => $usulan !== null && abs($usulan - $menit) < 0.005 ? 'rata' : 'manual',
                'sampel' => $d['sampel'] ?? null,
                'usulan' => $usulan,
            ];
        }

        $n = $this->ws->simpan((int) $data['divisi'], $isi, Carbon::parse($data['berlaku_mulai']), $request->user()?->id, $data['catatan'] ?? null);
        $pesan = $n ? "$n waktu standar disimpan, berlaku mulai " . Carbon::parse($data['berlaku_mulai'])->format('d/m/Y') . '.' : 'Tidak ada perubahan.';

        // Simpan per baris (AJAX): kirim balik baris SKU itu saja.
        if ($request->wantsJson() && count($data['standar'] ?? []) === 1) {
            return $this->barisJson($request, (int) array_key_first($data['standar']), $pesan, false);
        }

        return back()->with('success', $pesan);
    }

    /**
     * Coret / pulihkan satu sampel dari hitungan standar (owner). Lewat AJAX: hanya baris SKU-nya
     * yang diganti, angka yang sedang diketik di baris lain tidak hilang.
     */
    public function coretSampel(Request $request, int $stepId)
    {
        $this->khususOwner($request);
        $data = $request->validate(['coret' => 'required|boolean', 'buka' => 'nullable|integer']);
        $this->ws->coretSampel($stepId, (bool) $data['coret'], $request->user()?->id);
        $pesan = $data['coret'] ? 'Sampel dicoret — tidak ikut hitungan standar.' : 'Sampel dipulihkan.';

        if ($request->wantsJson() && !empty($data['buka'])) {
            return $this->barisJson($request, (int) $data['buka'], $pesan, true);
        }

        return back()->with('buka', $data['buka'] ?? null)->with('success', $pesan);
    }

    /**
     * Dashboard Penilaian (baca-saja). Server mengirim daftar OP beberapa bulan beserta jatah &
     * rasio timer; poin, tingkat & bonus dihitung ulang di browser supaya owner bisa mencoba
     * parameter (pita, ambang, bonus) dan melihat dampaknya seketika sebelum menyimpan.
     * `simulasi=1` (bawaan) mengisi SKU tanpa standar disetujui dengan usulan median.
     */
    public function penilaian(Request $request, PenilaianKinerjaService $pk)
    {
        $this->khususOwner($request);
        $deptId = (int) $request->input('divisi', self::DIVISI[0]);
        abort_unless(in_array($deptId, self::DIVISI, true), 404);

        $bulanDari = fn ($v, Carbon $def) => preg_match('/^\d{4}-\d{2}$/', (string) $v) ? Carbon::parse($v . '-01') : $def;
        $sampai = $bulanDari($request->input('sampai'), now()->subMonth()->startOfMonth());
        $dari   = $bulanDari($request->input('dari'), $sampai->copy()->subMonths(2));
        if ($dari->gt($sampai)) [$dari, $sampai] = [$sampai, $dari];
        if ($dari->diffInMonths($sampai) > 11) $dari = $sampai->copy()->subMonths(11);
        $simulasi = $request->boolean('simulasi', true);

        $bulanList = [];
        $ops = [];
        $tanpaStandar = [];
        $standarSimulasi = 0;
        // Daftar OP mentah: status dinilai/cek ditentukan ulang di browser dari rasio.
        // Jatah dasar TANPA faktor merk (param default) — faktor merk diterapkan di browser.
        $pk->pakaiParam(['faktor_cek' => 10]);
        for ($b = $dari->copy(); $b->lte($sampai); $b->addMonth()) {
            $kunci = $b->format('Y-m');
            $bulanList[] = ['kunci' => $kunci, 'label' => $b->translatedFormat('M Y')];
            $r = $pk->opBulan($deptId, $b, $simulasi);
            $standarSimulasi = max($standarSimulasi, $r['standar_simulasi']);
            foreach ($r['op'] as $o) {
                $ops[] = [
                    'b'      => $kunci,
                    'op'     => $o['op'],
                    'url'    => route('production.orders.show', $o['op_id']),
                    'tgl'    => $o['selesai']?->format('d/m H:i'),
                    'minggu' => $o['selesai']?->copy()->startOfWeek()->format('d/m'),
                    'px'     => array_column($o['pelaksana'], 'id'),
                    'menit'  => $o['menit'],
                    'jatah'  => $o['jatah'],
                    'rasio'  => $o['rasio'] !== null ? round($o['rasio'], 4) : null,
                    'sim'    => $o['simulasi'],
                    'merk'   => $o['merk'],
                    'mj'     => (object) $o['mj'],
                    'putusan' => $o['putusan'] ?? null,
                    'isi'    => collect($o['rincian'])->map(fn ($x) => $x['sku'] . ' ' . rtrim(rtrim(number_format($x['qty'], 2, ',', ''), '0'), ',') . '×' . number_format($x['standar'], 2, ',', '.'))->implode(', '),
                ];
            }
            foreach ($r['tanpa_standar'] as $o) {
                $tanpaStandar[] = ['b' => $kunci, 'op' => $o['op'], 'px' => array_column($o['pelaksana'], 'id'), 'kurang' => $o['kurang']];
            }
        }

        $pelaksana = DepartmentExecutor::where('department_id', $deptId)->orderBy('name')->get(['id', 'name', 'is_active', 'created_at'])
            ->map(fn ($e) => [
                'id'        => $e->id,
                'nama'      => $e->name,
                'aktif'     => (bool) $e->is_active,
                'percobaan' => $e->created_at && $e->created_at->gt(now()->subDays(WaktuStandarService::HARI_PERCOBAAN)),
            ])
            ->filter(fn ($e) => collect($ops)->contains(fn ($o) => in_array($e['id'], $o['px'], true))
                || collect($tanpaStandar)->contains(fn ($o) => in_array($e['id'], $o['px'], true)))
            ->values();

        $pk2 = app(PenilaianKinerjaService::class); // param tersimpan, bukan yang dipinjam di atas

        return view('erp.production.waktu-standar.penilaian', [
            'divisi'          => Department::whereIn('id', self::DIVISI)->get(['id', 'name']),
            'deptId'          => $deptId,
            'dari'            => $dari,
            'sampai'          => $sampai,
            'simulasi'        => $simulasi,
            'standarSimulasi' => $standarSimulasi,
            'data'            => [
                'bulan'        => $bulanList,
                'pelaksana'    => $pelaksana,
                'op'           => $ops,
                'tanpaStandar' => $tanpaStandar,
                'param'        => $pk2->param(),
                // Merk di daftar Pengaturan Produksi + merk lama yang masih tercatat di OP.
                'merkDaftar'   => collect(\App\Models\ProductionSetting::daftarMerkAkrilik())
                    ->merge(collect($ops)->pluck('merk')->flatten())
                    ->merge(array_keys($pk2->param()['faktor_merk']))->unique()->values(),
                'default'      => PenilaianKinerjaService::rapikan(PenilaianKinerjaService::DEFAULT),
            ],
        ]);
    }

    /**
     * Rapor per orang (owner): A. ringkasan nilai satu bulan, B. Performa = daftar OP
     * dikelompokkan per poin supaya asal nilainya bisa ditelusuri, C. komponen lain (hasil kerja,
     * keterampilan) menyusul. Memakai parameter TERSIMPAN & standar resmi (tanpa simulasi) —
     * ini angka "yang benar", bukan tempat mencoba parameter.
     */
    public function rapor(Request $request, PenilaianKinerjaService $pk)
    {
        $this->khususOwner($request);
        $deptId = (int) $request->input('divisi', self::DIVISI[0]);
        abort_unless(in_array($deptId, self::DIVISI, true), 404);
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('bulan')) ? Carbon::parse($request->input('bulan') . '-01') : now()->startOfMonth();

        $hasil = $pk->bulan($deptId, $bulan);
        $semuaOrang = DepartmentExecutor::where('department_id', $deptId)->orderByDesc('is_active')->orderBy('name')
            ->get(['id', 'name', 'is_active', 'created_at']);
        $orangId = (int) $request->input('orang', 0)
            ?: ($hasil['pelaksana']->sortByDesc('n')->first()['id'] ?? $semuaOrang->first()?->id);
        $orang = $semuaOrang->firstWhere('id', $orangId);
        abort_unless($orang, 404);

        $nilai = $hasil['pelaksana']->firstWhere('id', $orangId);
        $milik = fn ($o) => collect($o['pelaksana'])->contains('id', $orangId);
        $ops = $hasil['op']->filter($milik)->values();
        $tanpa = $hasil['tanpa_standar']->filter($milik)->values();

        // Langkah yang timernya pernah dikoreksi owner — ditandai di daftar.
        $dikoreksi = \App\Modules\Production\Models\ProductionStepTimeLog::whereIn('production_order_step_id', $ops->pluck('step_id'))
            ->whereNotNull('koreksi_grup')->distinct()->pluck('production_order_step_id')->flip();

        $kelompok = [
            'p2'        => ['judul' => '2 poin — lebih cepat dari target', 'warna' => 'emerald', 'op' => $ops->where('status', 'dinilai')->where('poin', 2)],
            'p1'        => ['judul' => '1 poin — sesuai target', 'warna' => 'gray', 'op' => $ops->where('status', 'dinilai')->where('poin', 1)],
            'p0'        => ['judul' => '0 poin — lebih lambat dari target', 'warna' => 'red', 'op' => $ops->where('status', 'dinilai')->where('poin', 0)],
            'cek'       => ['judul' => 'Ditahan — menunggu putusan owner (Meja Owner)', 'warna' => 'amber', 'op' => $ops->where('status', 'cek')],
            'diabaikan' => ['judul' => 'Diabaikan owner — tidak ikut nilai', 'warna' => 'slate', 'op' => $ops->where('status', 'diabaikan')],
        ];

        // Tren 3 bulan terakhir (sampai bulan dipilih) untuk orang ini.
        $tren = [];
        for ($b = $bulan->copy()->subMonths(2); $b->lte($bulan); $b->addMonth()) {
            $r = $b->eq($bulan) ? $nilai : $pk->bulan($deptId, $b)['pelaksana']->firstWhere('id', $orangId);
            $tren[] = ['label' => $b->translatedFormat('M Y'), 'rata' => $r['rata'] ?? null, 'n' => $r['n'] ?? 0, 'tingkat' => $r['tingkat'] ?? null];
        }

        // Keterampilan memakai instance $pk yang sama → hasil opBulan bulan-bulan tadi dipakai ulang.
        $keterampilan = (new \App\Modules\Production\Services\KeterampilanService($pk))
            ->orang($deptId, $orangId, $bulan->copy()->endOfMonth());

        return view('erp.production.waktu-standar.rapor', [
            'divisi'     => Department::whereIn('id', self::DIVISI)->get(['id', 'name']),
            'deptId'     => $deptId,
            'bulan'      => $bulan,
            'semuaOrang' => $semuaOrang,
            'orang'      => $orang,
            'percobaan'  => $orang->created_at && $orang->created_at->gt(now()->subDays(WaktuStandarService::HARI_PERCOBAAN)),
            'nilai'      => $nilai,
            'kelompok'   => $kelompok,
            'tanpa'      => $tanpa,
            'dikoreksi'  => $dikoreksi,
            'tren'       => $tren,
            'param'      => $pk->param(),
            'berjalan'   => $bulan->isSameMonth(now()),
            'keterampilan' => $keterampilan,
        ]);
    }

    /** Penilaian operator CNC Tahap 0: mesin diam saat ada antrean (dari data ERP). */
    public function cnc(Request $request, \App\Modules\Production\Services\JedaMesinService $jeda)
    {
        $this->khususOwner($request);
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('bulan')) ? Carbon::parse($request->input('bulan') . '-01') : now()->startOfMonth();
        $semua = \App\Modules\Production\Services\JedaMesinService::operator();
        $operator = $semua->firstWhere('id', (int) $request->input('orang')) ?? $semua->first();

        return view('erp.production.waktu-standar.cnc', [
            'semua'    => $semua,
            'operator' => $operator,
            'bulan'    => $bulan,
            'hasil'    => $operator ? $jeda->bulan($operator, $bulan) : null,
            'berjalan' => $bulan->isSameMonth(now()),
        ]);
    }

    public function simpanParam(Request $request, PenilaianKinerjaService $pk)
    {
        $this->khususOwner($request);
        $data = $request->validate([
            'param'                  => 'required|array',
            'param.pita'             => 'required|numeric|min:0|max:0.5',
            'param.faktor_cek'       => 'required|numeric|min:1.2|max:10',
            'param.min_op'           => 'required|integer|min:0|max:100',
            'param.bobot'            => 'required|in:op,jatah',
            'param.tingkat'          => 'required|array|min:2|max:6',
            'param.tingkat.*.min'    => 'required|numeric|min:0|max:2',
            'param.tingkat.*.label'  => 'required|string|max:30',
            'param.tingkat.*.bonus'  => 'required|numeric|min:0|max:100000000',
            'param.faktor_merk'      => 'nullable|array|max:30',
            'param.faktor_merk.*'    => 'numeric|min:0.5|max:2',
        ]);
        $pk->simpanParam($data['param']);

        return back()->with('success', 'Parameter penilaian disimpan.');
    }
}
