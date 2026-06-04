<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\Production\Models\Department;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\KaryawanSchedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class KaryawanController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('show_inactive')) {
            $showInactive = $request->boolean('show_inactive');
            session(['karyawan.show_inactive' => $showInactive]);
        } else {
            $showInactive = (bool) session('karyawan.show_inactive', false);
        }

        $query = Karyawan::with('department')->orderBy('staf_code');

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($x) use ($q) {
                $x->where('staf_code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%");
            });
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if (!$showInactive) {
            $query->where('is_active', true);
        }

        $karyawans = $query->paginate(25)->withQueryString();
        $departments = Department::orderBy('name')->get();

        return view('erp.sdm.karyawan.index', compact('karyawans', 'departments', 'showInactive'));
    }

    public function create()
    {
        $departments = Department::orderBy('name')->get();
        return view('erp.sdm.karyawan.create', compact('departments'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $schedules = $this->parseSchedules($request);

        if (empty($data['staf_code'])) {
            $data['staf_code'] = $this->generateStafCode();
        }

        DB::transaction(function () use ($data, $schedules, &$karyawan) {
            $karyawan = Karyawan::create($data);
            $this->saveSchedules($karyawan, $schedules);
        });

        return redirect(list_url('sdm.karyawan.index'))->with('success', 'Karyawan berhasil ditambahkan.');
    }

    protected function generateStafCode(): string
    {
        $latest = Karyawan::withTrashed()
            ->where('staf_code', 'LIKE', 'KRY-%')
            ->orderByDesc('staf_code')
            ->value('staf_code');

        $next = $latest ? ((int) substr($latest, 4)) + 1 : 1;
        return 'KRY-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    public function show(int $id)
    {
        $karyawan = Karyawan::with(['department', 'schedules'])->findOrFail($id);
        $slipGajis = $karyawan->slipGajis()->with('periode')->orderByDesc('id')->limit(20)->get();

        return view('erp.sdm.karyawan.show', compact('karyawan', 'slipGajis'));
    }

    public function edit(int $id)
    {
        $karyawan = Karyawan::with('schedules')->findOrFail($id);
        $departments = Department::orderBy('name')->get();
        return view('erp.sdm.karyawan.edit', compact('karyawan', 'departments'));
    }

    public function update(Request $request, int $id)
    {
        $karyawan = Karyawan::findOrFail($id);
        $data = $this->validateData($request, $karyawan->id);
        $schedules = $this->parseSchedules($request);
        $applyToAll = $request->boolean('apply_to_all');

        DB::transaction(function () use ($karyawan, $data, $schedules, $applyToAll) {
            $karyawan->update($data);
            $this->saveSchedules($karyawan, $schedules);

            if ($applyToAll) {
                $others = Karyawan::where('id', '!=', $karyawan->id)
                    ->where('is_active', true)
                    ->get();
                foreach ($others as $other) {
                    $this->saveSchedules($other, $schedules);
                }
            }
        });

        $msg = 'Karyawan berhasil diperbarui.';
        if ($applyToAll) {
            $msg .= ' Jadwal kerja & hari libur juga diterapkan ke semua karyawan aktif lainnya.';
        }
        return redirect(list_url('sdm.karyawan.index'))->with('success', $msg);
    }

    public function destroy(int $id)
    {
        $karyawan = Karyawan::findOrFail($id);

        if (!$karyawan->canBeDeleted()) {
            return back()->with('error', 'Karyawan sudah digunakan (slip gaji / absensi / kasbon / SP / divisi). Silakan gunakan Arsipkan.');
        }

        DB::transaction(function () use ($karyawan) {
            $karyawan->schedules()->delete();
            $karyawan->forceDelete();
        });

        return back()->with('success', 'Karyawan dihapus permanen.');
    }

    public function archive(int $id)
    {
        $karyawan = Karyawan::findOrFail($id);
        $karyawan->update([
            'is_active'   => false,
            'resign_date' => $karyawan->resign_date ?? now()->toDateString(),
        ]);

        return back()->with('success', 'Karyawan diarsipkan.');
    }

    public function searchByDepartment(int $deptId)
    {
        $karyawans = Karyawan::where('department_id', $deptId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'staf_code', 'name', 'jabatan']);

        return response()->json($karyawans);
    }

    /**
     * Karyawan aktif kandidat untuk dipindahkan ke divisi {deptId}.
     * Tampilkan semua karyawan minus yang sudah di divisi tsb, sertakan nama divisi sekarang.
     */
    public function searchExceptDepartment(int $deptId)
    {
        $karyawans = Karyawan::with('department:id,name')
            ->where('is_active', true)
            ->where(function ($q) use ($deptId) {
                $q->where('department_id', '!=', $deptId)
                  ->orWhereNull('department_id');
            })
            ->orderBy('name')
            ->get(['id', 'staf_code', 'name', 'jabatan', 'department_id']);

        return response()->json($karyawans->map(fn($k) => [
            'id'             => $k->id,
            'staf_code'      => $k->staf_code,
            'name'           => $k->name,
            'jabatan'        => $k->jabatan,
            'current_dept'   => $k->department->name ?? null,
        ]));
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        $unique = $id ? ",{$id}" : '';
        $stafCodeRule = $id
            ? 'required|string|max:30|unique:sdm_karyawan,staf_code' . $unique
            : 'nullable|string|max:30|unique:sdm_karyawan,staf_code';

        $data = $request->validate([
            'staf_code'             => $stafCodeRule,
            'name'                  => 'required|string|max:150',
            'department_id'         => 'nullable|integer|exists:production_departments,id',
            'jabatan'               => 'nullable|string|max:100',
            'gaji_pokok'            => 'nullable|string',
            'tunjangan_pegawai'     => 'nullable|string',
            'mulai_kerja'           => 'nullable|date',
            'hak_cuti'              => 'nullable|integer|min:0|max:60',
            'user_id_fingerprint'   => 'nullable|string|max:50',
            'is_active'             => 'nullable|boolean',
            'hp'                    => 'nullable|string|max:30',
            'email'                 => 'nullable|email|max:150',
            'alamat'                => 'nullable|string',
            'nik'                   => 'nullable|string|max:30',
            'npwp'                  => 'nullable|string|max:30',
            'bpjs'                  => 'nullable|string|max:30',
            'bpjs_kesehatan_amount' => 'nullable|string',
            'bpjs_tk_amount'        => 'nullable|string',
            'pph21_amount'          => 'nullable|string',
            'ptkp_category'         => 'nullable|in:NONE,A,B,C',
            'ikut_bpjs_kesehatan'   => 'nullable|boolean',
            'ikut_bpjs_tk'          => 'nullable|boolean',
            'bank_name'             => 'nullable|string|max:50',
            'bank_account_number'   => 'nullable|string|max:50',
            'bank_account_holder'   => 'nullable|string|max:150',
        ]);
        $data['ptkp_category']       = $data['ptkp_category'] ?? 'NONE';
        $data['ikut_bpjs_kesehatan'] = $request->boolean('ikut_bpjs_kesehatan');
        $data['ikut_bpjs_tk']        = $request->boolean('ikut_bpjs_tk');

        $raw = $request->input('gaji_pokok');
        $data['gaji_pokok'] = $raw !== null && $raw !== '' ? (float) clean_number($raw) : 0;

        foreach (['tunjangan_pegawai', 'bpjs_kesehatan_amount', 'bpjs_tk_amount', 'pph21_amount'] as $f) {
            $val = $request->input($f);
            $data[$f] = $val !== null && $val !== '' ? (float) clean_number($val) : 0;
        }

        $data['is_active'] = $request->boolean('is_active', true);
        $data['hak_cuti']  = $data['hak_cuti'] ?? 12;

        return $data;
    }

    /**
     * Parse 1 master schedule + libur_days[] -> 7 day rows.
     */
    protected function parseSchedules(Request $request): array
    {
        $m         = $request->input('schedule_master', []);
        $liburDays = array_map('intval', (array) $request->input('libur_days', []));

        $hasLembur = true;

        $base = [
            'jam_masuk'                  => $m['jam_masuk'] ?? null,
            'awal_absen_masuk'           => (int) ($m['awal_absen_masuk'] ?? 90),
            'late_in_minutes'            => (int) ($m['late_in_minutes'] ?? 10),
            'akhir_absen_masuk'          => (int) ($m['akhir_absen_masuk'] ?? 120),

            'jam_pulang'                 => $m['jam_pulang'] ?? null,
            'awal_absen_pulang'          => (int) ($m['awal_absen_pulang'] ?? 120),
            'early_out_minutes'          => (int) ($m['early_out_minutes'] ?? 0),
            'akhir_absen_pulang'         => (int) ($m['akhir_absen_pulang'] ?? 15),

            'jam_istirahat_start'        => $m['jam_istirahat_start'] ?? null,
            'jam_istirahat_end'          => $m['jam_istirahat_end'] ?? null,

            'has_lembur'                 => $hasLembur,
            'jam_masuk_lembur'           => $hasLembur ? ($m['jam_masuk_lembur'] ?? null) : null,
            'awal_absen_lembur_masuk'    => (int) ($m['awal_absen_lembur_masuk'] ?? 15),
            'toleransi_lembur_masuk'     => (int) ($m['toleransi_lembur_masuk'] ?? 0),
            'akhir_absen_lembur_masuk'   => (int) ($m['akhir_absen_lembur_masuk'] ?? 120),

            'jam_pulang_lembur'          => $hasLembur ? ($m['jam_pulang_lembur'] ?? null) : null,
            'awal_absen_lembur_pulang'   => (int) ($m['awal_absen_lembur_pulang'] ?? 160),
            'toleransi_lembur_pulang'    => (int) ($m['toleransi_lembur_pulang'] ?? 150),
            'akhir_absen_lembur_pulang'  => (int) ($m['akhir_absen_lembur_pulang'] ?? 90),

            'jam_istirahat_lembur_start' => $hasLembur ? ($m['jam_istirahat_lembur_start'] ?? null) : null,
            'jam_istirahat_lembur_end'   => $hasLembur ? ($m['jam_istirahat_lembur_end'] ?? null) : null,
        ];

        $nullableJamFields = [
            'jam_masuk', 'jam_pulang', 'jam_istirahat_start', 'jam_istirahat_end',
            'jam_masuk_lembur', 'jam_pulang_lembur',
            'jam_istirahat_lembur_start', 'jam_istirahat_lembur_end',
        ];

        $out = [];
        foreach ([1,2,3,4,5,6,0] as $dow) {
            $isOff = in_array($dow, $liburDays, true);
            $row = array_merge(['day_of_week' => $dow, 'is_off' => $isOff], $base);
            if ($isOff) {
                foreach ($nullableJamFields as $f) {
                    $row[$f] = null;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Replace semua jadwal karyawan (upsert per day_of_week).
     */
    protected function saveSchedules(Karyawan $karyawan, array $rows): void
    {
        foreach ($rows as $row) {
            KaryawanSchedule::updateOrCreate(
                ['karyawan_id' => $karyawan->id, 'day_of_week' => $row['day_of_week']],
                $row,
            );
        }
    }
}
