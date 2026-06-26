<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\Karyawan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class IzinController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceOverride::with('karyawan')->orderByDesc('tanggal')->orderBy('karyawan_id');

        if ($request->filled('karyawan_id')) {
            $query->where('karyawan_id', $request->karyawan_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('from')) {
            $query->whereDate('tanggal', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('tanggal', '<=', $request->to);
        }

        $izins = $query->paginate(50)->withQueryString();
        $karyawans = Karyawan::where('is_active', true)->orderBy('name')->get();

        return view('erp.sdm.izin.index', compact('izins', 'karyawans'));
    }

    public function create(Request $request)
    {
        $karyawans = Karyawan::where('is_active', true)->orderBy('name')->get();
        $selected = $request->karyawan_id;
        // Toleransi (force majeure) sengaja TIDAK di sini: super admin menangani lewat
        // edit langsung di Absensi (manusia pengendali utama). Lihat IzinRequest::TYPES utk PWA.
        $types = AttendanceOverride::TYPES;
        return view('erp.sdm.izin.create', compact('karyawans', 'selected', 'types'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'karyawan_id'         => 'required|exists:sdm_karyawan,id',
            'tanggal'             => 'required|date',
            'type'                => 'required|in:cuti,sakit,izin_pagi,izin_sore,lembur,tukar_hari,tukar_setengah_hari,penyesuaian_jam',
            'paired_date'         => 'nullable|date|different:tanggal',
            'sesi'                => 'nullable|in:pagi,sore',
            'paired_sesi'         => 'nullable|in:pagi,sore',
            'jam_masuk_override'  => 'nullable|date_format:H:i',
            'jam_pulang_override' => 'nullable|date_format:H:i',
            'notes'               => 'nullable|string|max:255',
        ]);

        // Field requirements per type
        if (in_array($data['type'], ['tukar_hari', 'tukar_setengah_hari'], true) && empty($data['paired_date'])) {
            return back()->withErrors(['paired_date' => 'Tanggal Pasangan wajib diisi untuk tipe Tukar Hari / Tukar Setengah Hari.'])->withInput();
        }
        if ($data['type'] === 'tukar_setengah_hari' && (empty($data['sesi']) || empty($data['paired_sesi']))) {
            return back()->withErrors(['sesi' => 'Sesi (pagi/sore) wajib diisi untuk Tukar Setengah Hari.'])->withInput();
        }
        if ($data['type'] === 'penyesuaian_jam' && (empty($data['jam_masuk_override']) && empty($data['jam_pulang_override']))) {
            return back()->withErrors(['jam_masuk_override' => 'Minimal salah satu Jam Masuk / Pulang harus diisi untuk Penyesuaian Jam.'])->withInput();
        }

        // Nullify fields yang tidak relevan dengan type
        if (! in_array($data['type'], ['tukar_hari', 'tukar_setengah_hari'], true)) {
            $data['paired_date'] = null;
            $data['paired_sesi'] = null;
        }
        if ($data['type'] !== 'tukar_setengah_hari') {
            $data['sesi']        = null;
            $data['paired_sesi'] = null;
        }
        if ($data['type'] !== 'penyesuaian_jam') {
            $data['jam_masuk_override']  = null;
            $data['jam_pulang_override'] = null;
        }

        // Cegah duplikat exact (karyawan + tanggal + type)
        $exists = AttendanceOverride::where('karyawan_id', $data['karyawan_id'])
            ->whereDate('tanggal', $data['tanggal'])
            ->where('type', $data['type'])
            ->exists();
        if ($exists) {
            return back()->withErrors(['type' => 'Izin dengan tipe yang sama sudah ada untuk karyawan & tanggal tsb.'])->withInput();
        }

        AttendanceOverride::create($data);
        return redirect(list_url('sdm.izin.index'))->with('success', 'Izin disimpan.');
    }

    public function destroy(int $id)
    {
        $izin = AttendanceOverride::findOrFail($id);
        $izin->delete();
        return back()->with('success', 'Izin dihapus.');
    }
}
