<?php

namespace App\Modules\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\SDM\Models\Karyawan;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::with([
            'executors.karyawan',
            'karyawans' => fn($q) => $q->where('is_active', true)->orderBy('name'),
        ])->orderBy('code')->get();
        return view('erp.production.departments.index', compact('departments'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'type' => 'required|in:produksi,non_produksi',
        ]);

        $code = $this->generateDepartmentCode($request->type);

        $dept = Department::create([
            'code'        => $code,
            'name'        => $request->name,
            'description' => $request->description,
            'type'        => $request->type,
        ]);

        return back()->with('success', "Departemen {$dept->name} ({$code}) berhasil ditambahkan.");
    }

    protected function generateDepartmentCode(string $type): string
    {
        $prefix = $type === 'produksi' ? 'PRD' : 'MKT';

        $latest = Department::where('code', 'LIKE', "{$prefix}-%")
            ->orderByDesc('code')
            ->value('code');

        $next = $latest ? ((int) substr($latest, strlen($prefix) + 1)) + 1 : 1;
        return $prefix . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    public function update(Request $request, int $id)
    {
        $dept = Department::findOrFail($id);

        $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'type'        => 'required|in:produksi,non_produksi',
            'is_active'   => 'boolean',
        ]);

        $dept->update($request->only('name', 'description', 'type', 'is_active'));

        return back()->with('success', 'Departemen berhasil diperbarui.');
    }

    public function destroy(int $id)
    {
        $dept = Department::findOrFail($id);
        $dept->update(['is_active' => false]);
        return back()->with('success', 'Departemen dinonaktifkan.');
    }

    public function activate(int $id)
    {
        $dept = Department::findOrFail($id);
        $dept->update(['is_active' => true]);
        return back()->with('success', "Departemen {$dept->name} diaktifkan kembali.");
    }

    public function storeExecutor(Request $request, int $id)
    {
        $dept = Department::findOrFail($id);

        $request->validate([
            'karyawan_id' => 'nullable|exists:sdm_karyawan,id',
            'name'        => 'required_without:karyawan_id|nullable|string|max:100',
            'role'        => 'nullable|string|max:100',
        ]);

        $name = $request->input('name');
        $karyawanId = $request->input('karyawan_id');
        $movedFrom = null;

        if ($karyawanId) {
            $karyawan = Karyawan::find($karyawanId);
            if ($karyawan) {
                $name = $karyawan->name;
                if ((int) $karyawan->department_id !== $dept->id) {
                    $movedFrom = optional($karyawan->department)->name;
                    $karyawan->update(['department_id' => $dept->id]);
                }
            }
        }

        DepartmentExecutor::create([
            'department_id' => $dept->id,
            'karyawan_id'   => $karyawanId ?: null,
            'name'          => $name,
            'role'          => $request->role,
        ]);

        $msg = 'Eksekutor berhasil ditambahkan.';
        if ($movedFrom) {
            $msg .= " Karyawan dipindahkan dari divisi {$movedFrom} ke {$dept->name}.";
        }
        return back()->with('success', $msg);
    }

    public function destroyExecutor(int $id, int $execId)
    {
        DepartmentExecutor::where('department_id', $id)->findOrFail($execId)->delete();
        return back()->with('success', 'Eksekutor dihapus.');
    }

    /**
     * Pindah/tetapkan karyawan ke divisi ini (set department_id).
     */
    public function addMember(Request $request, int $id)
    {
        $dept = Department::findOrFail($id);
        $request->validate(['karyawan_id' => 'required|exists:sdm_karyawan,id']);

        $karyawan = Karyawan::findOrFail($request->karyawan_id);
        $movedFrom = optional($karyawan->department)->name;
        $karyawan->update(['department_id' => $dept->id]);

        $msg = "{$karyawan->name} ditetapkan sebagai anggota {$dept->name}.";
        if ($movedFrom && $movedFrom !== $dept->name) {
            $msg = "{$karyawan->name} dipindahkan dari {$movedFrom} ke {$dept->name}.";
        }

        return back()->with('success', $msg);
    }

    /**
     * Lepaskan karyawan dari divisi (set department_id = null).
     */
    public function removeMember(int $id, int $karyawanId)
    {
        $karyawan = Karyawan::where('department_id', $id)->findOrFail($karyawanId);
        $karyawan->update(['department_id' => null]);

        return back()->with('success', "{$karyawan->name} dilepas dari divisi.");
    }
}
