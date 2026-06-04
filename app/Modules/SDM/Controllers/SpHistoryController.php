<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\SpHistory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SpHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = SpHistory::with(['karyawan:id,staf_code,name'])
            ->orderByDesc('tanggal_terbit')
            ->orderByDesc('id');

        if ($request->filled('karyawan_id')) {
            $query->where('karyawan_id', $request->karyawan_id);
        }
        if ($request->filled('sanksi')) {
            $query->where('sanksi', $request->sanksi);
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'aktif');
        }

        $records = $query->paginate(25)->withQueryString();
        $karyawans = Karyawan::where('is_active', true)->orderBy('name')->get(['id', 'staf_code', 'name']);

        return view('erp.sdm.sp.index', compact('records', 'karyawans'));
    }

    public function create()
    {
        $karyawans = Karyawan::where('is_active', true)->orderBy('name')->get(['id', 'staf_code', 'name', 'sanksi']);
        return view('erp.sdm.sp.create', compact('karyawans'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by'] = auth()->id();
        $data['is_active']  = true;

        $sp = SpHistory::create($data);
        // Sinkronkan field sanksi di master karyawan ke level SP yang baru terbit.
        $sp->karyawan->update(['sanksi' => $sp->sanksi]);

        return redirect()->route('sdm.sp.index')->with('success', "Surat Peringatan {$sp->sanksi} untuk {$sp->karyawan->name} dicatat.");
    }

    public function edit(int $id)
    {
        $sp = SpHistory::findOrFail($id);
        $karyawans = Karyawan::where('is_active', true)->orderBy('name')->get(['id', 'staf_code', 'name']);
        return view('erp.sdm.sp.edit', compact('sp', 'karyawans'));
    }

    public function update(Request $request, int $id)
    {
        $sp = SpHistory::findOrFail($id);
        $data = $this->validateData($request);
        $sp->update($data);

        return redirect()->route('sdm.sp.index')->with('success', 'Surat Peringatan diperbarui.');
    }

    public function destroy(int $id)
    {
        $sp = SpHistory::findOrFail($id);
        $sp->update(['is_active' => false]);

        return back()->with('success', 'Surat Peringatan dicabut.');
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'karyawan_id'    => 'required|exists:sdm_karyawan,id',
            'sanksi'         => 'required|in:SP1,SP2,SP3',
            'tanggal_terbit' => 'required|date',
            'berlaku_sampai' => 'nullable|date|after:tanggal_terbit',
            'alasan'         => 'required|string',
            'catatan'        => 'nullable|string',
        ]);
    }
}
