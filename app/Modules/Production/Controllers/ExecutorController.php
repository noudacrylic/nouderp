<?php

namespace App\Modules\Production\Controllers;

use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Services\ExecutorScheduleResolver;
use App\Modules\SDM\Models\Karyawan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class ExecutorController extends Controller
{
    public function index(Request $request, ExecutorScheduleResolver $resolver)
    {
        $departmentsList = Department::where('type', 'produksi')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Belum ada divisi sama sekali → tampilkan empty state.
        if ($departmentsList->isEmpty()) {
            return view('erp.production.executors.index', [
                'departmentsList' => $departmentsList,
                'department'      => null,
                'execInfo'        => [],
            ]);
        }

        // Default: redirect ke divisi pertama agar selalu ada divisi terpilih (mirip Proses Produksi).
        $selectedId = $request->get('department_id');
        if (!$selectedId || !$departmentsList->contains('id', (int) $selectedId)) {
            return redirect()->route('production.executors.index', [
                'department_id' => $departmentsList->first()->id,
            ]);
        }

        $department = Department::where('type', 'produksi')
            ->where('is_active', true)
            ->with([
                'executors' => fn($q) => $q->orderBy('parent_executor_id')->orderBy('id'),
                'executors.karyawan',
                'executors.parent',
                'karyawans' => fn($q) => $q->where('is_active', true)->orderBy('name'),
            ])
            ->findOrFail((int) $selectedId);

        // Resolve schedule + scan status per executor untuk hari ini
        $now = now();
        $execInfo = []; // [executor_id => ['schedule'=>..., 'check_in'=>..., 'check_out'=>..., 'overtime_in'=>..., 'status'=>..., 'effective_karyawan_id'=>...]]
        foreach ($department->executors as $exec) {
            $karyawanId = $exec->effectiveKaryawanId();
            $sched = $karyawanId ? $resolver->forKaryawan($karyawanId, $now) : null;
            $checkIn = $karyawanId ? $resolver->hasCheckedIn($karyawanId, $now) : null;
            $checkOut = $karyawanId ? $resolver->hasCheckedOut($karyawanId, $now) : null;
            $overtimeIn = $karyawanId ? $resolver->hasOvertimeIn($karyawanId, $now) : null;
            $status = $resolver->currentStatus($sched, $now, $overtimeIn);

            $execInfo[$exec->id] = [
                'schedule'              => $sched,
                'check_in'              => $checkIn,
                'check_out'             => $checkOut,
                'overtime_in'           => $overtimeIn,
                'status'                => $status,
                'effective_karyawan_id' => $karyawanId,
            ];
        }

        return view('erp.production.executors.index', compact('departmentsList', 'department', 'execInfo'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'department_id'      => 'required|exists:production_departments,id',
            'karyawan_id'        => 'nullable|exists:sdm_karyawan,id',
            'parent_executor_id' => 'nullable|exists:production_department_executors,id',
            'name'               => 'required_without:karyawan_id|nullable|string|max:100',
            'role'               => 'nullable|string|max:100',
            'mesin_1'            => 'nullable|string|max:100',
            'mesin_2'            => 'nullable|string|max:100',
            'mesin_3'            => 'nullable|string|max:100',
        ]);

        $deptId = (int) $request->department_id;
        $karyawanId = $request->karyawan_id ? (int) $request->karyawan_id : null;
        $parentId = $request->parent_executor_id ? (int) $request->parent_executor_id : null;
        $name = $request->name;

        if ($parentId) {
            $parent = DepartmentExecutor::find($parentId);
            if (!$parent || (int) $parent->department_id !== $deptId) {
                return back()->with('error', 'Parent eksekutor harus dari divisi yang sama.');
            }
        }

        if ($karyawanId) {
            $karyawan = Karyawan::findOrFail($karyawanId);
            $name = $karyawan->name;

            $exists = DepartmentExecutor::where('department_id', $deptId)
                ->where('karyawan_id', $karyawanId)
                ->exists();
            if ($exists) {
                return back()->with('error', "{$name} sudah jadi eksekutor di divisi ini.");
            }

            if ((int) $karyawan->department_id !== $deptId) {
                $karyawan->update(['department_id' => $deptId]);
            }
        }

        DepartmentExecutor::create([
            'department_id'      => $deptId,
            'karyawan_id'        => $karyawanId,
            'parent_executor_id' => $parentId,
            'name'               => $name,
            'role'               => $request->role,
            'mesin_1'            => $request->mesin_1,
            'mesin_2'            => $request->mesin_2,
            'mesin_3'            => $request->mesin_3,
        ]);

        return back()->with('success', "Eksekutor {$name} ditambahkan.");
    }

    public function update(Request $request, int $id)
    {
        $exec = DepartmentExecutor::findOrFail($id);

        $request->validate([
            'name'               => 'nullable|string|max:100',
            'role'               => 'nullable|string|max:100',
            'parent_executor_id' => 'nullable|exists:production_department_executors,id',
            'mesin_1'            => 'nullable|string|max:100',
            'mesin_2'            => 'nullable|string|max:100',
            'mesin_3'            => 'nullable|string|max:100',
        ]);

        $parentId = $request->parent_executor_id ? (int) $request->parent_executor_id : null;

        if ($parentId) {
            if ($parentId === $exec->id) {
                throw ValidationException::withMessages(['parent_executor_id' => 'Parent tidak boleh diri sendiri.']);
            }
            $parent = DepartmentExecutor::find($parentId);
            if (!$parent || (int) $parent->department_id !== (int) $exec->department_id) {
                throw ValidationException::withMessages(['parent_executor_id' => 'Parent harus dari divisi yang sama.']);
            }
            // Circular check: parent_executor_id tidak boleh berada di descendant tree exec
            $descendantIds = $exec->descendantIds();
            if (in_array($parentId, $descendantIds, true)) {
                throw ValidationException::withMessages(['parent_executor_id' => 'Tidak boleh memilih parent dari descendant sendiri (circular).']);
            }
        }

        $payload = $request->only('role', 'mesin_1', 'mesin_2', 'mesin_3');
        $payload['parent_executor_id'] = $parentId;
        if (!$exec->karyawan_id && $request->filled('name')) {
            $payload['name'] = $request->name;
        }
        $exec->update($payload);

        return back()->with('success', 'Eksekutor diperbarui.');
    }

    public function destroy(int $id)
    {
        $exec = DepartmentExecutor::findOrFail($id);
        $name = $exec->karyawan?->name ?? $exec->name;
        $exec->delete();
        return back()->with('success', "Eksekutor {$name} dihapus.");
    }
}
