<?php

namespace App\Http\Controllers\Settings;

use App\Models\User;
use App\Modules\SDM\Models\Karyawan;
use App\Services\MenuRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $u = auth()->user();
            abort_unless($u && in_array($u->role, ['super_admin', 'admin'], true), 403);
            return $next($request);
        });
    }

    public function index()
    {
        $authUser = auth()->user();

        $query = User::with('karyawan:id,name,staf_code')
            ->withCount('menuPermissions');

        // Admin tidak boleh lihat super_admin atau admin lain (selain dirinya)
        if ($authUser->isAdmin()) {
            $query->where(function ($q) use ($authUser) {
                $q->where('role', 'user')
                  ->orWhere('id', $authUser->id);
            });
        }

        $users = $query->orderBy('role')->orderBy('username')->get();

        $karyawanOptions = Karyawan::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'staf_code', 'name']);

        $menuTree = app(MenuRegistry::class)->treeForUI();

        // Registrasi mandiri PWA Karyawan yang menunggu diaktifkan (gate keamanan ke-2).
        $pendingRegistrations = User::with('karyawan:id,name,staf_code')
            ->where('role', 'user')
            ->where('is_active', false)
            ->whereNotNull('karyawan_id')
            ->orderByDesc('created_at')
            ->get();

        return view('erp.settings.users.index', compact('users', 'karyawanOptions', 'menuTree', 'authUser', 'pendingRegistrations'));
    }

    public function store(Request $request)
    {
        $authUser = auth()->user();

        $data = $this->validatedData($request, null);
        $this->guardRole($authUser, $data['role']);

        $user = User::create([
            'username'     => $data['username'],
            'name'         => $data['name'],
            'email'        => $data['email'] ?: null,
            'password'     => Hash::make($data['password']),
            'role'         => $data['role'],
            'is_active'    => $request->boolean('is_active', true),
            'karyawan_id'  => $data['karyawan_id'] ?: null,
        ]);

        if ($user->role === 'user') {
            $this->syncPermissions($user, (array) $request->input('permissions', []));
        }

        return redirect()->route('settings.users.index')->with('success', "User {$user->username} dibuat.");
    }

    public function update(Request $request, int $id)
    {
        $authUser = auth()->user();
        $user = User::findOrFail($id);
        $this->guardEditTarget($authUser, $user);

        $data = $this->validatedData($request, $user);

        // Hanya super_admin yang boleh ubah role; admin tidak boleh promosikan ke admin/super_admin
        if ($data['role'] !== $user->role) {
            $this->guardRole($authUser, $data['role']);
            // Last super_admin guard
            if ($user->role === 'super_admin' && $data['role'] !== 'super_admin') {
                $this->assertNotLastSuperAdmin($user);
            }
        }

        $payload = [
            'username'     => $data['username'],
            'name'         => $data['name'],
            'email'        => $data['email'] ?: null,
            'role'         => $data['role'],
            'is_active'    => $request->boolean('is_active', true),
            'karyawan_id'  => $data['karyawan_id'] ?: null,
        ];
        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }
        $user->update($payload);

        if ($user->role === 'user') {
            $this->syncPermissions($user, (array) $request->input('permissions', []));
        } else {
            // super_admin / admin tidak perlu permission row
            $user->menuPermissions()->delete();
        }

        return redirect()->route('settings.users.index')->with('success', "User {$user->username} diperbarui.");
    }

    public function destroy(int $id)
    {
        $authUser = auth()->user();
        $user = User::findOrFail($id);
        $this->guardEditTarget($authUser, $user);

        abort_if($user->id === $authUser->id, 422, 'Tidak bisa hapus akun sendiri.');

        if ($user->role === 'super_admin') {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->delete();
        return redirect()->route('settings.users.index')->with('success', "User {$user->username} dihapus.");
    }

    public function toggleActive(int $id)
    {
        $authUser = auth()->user();
        $user = User::findOrFail($id);
        $this->guardEditTarget($authUser, $user);

        abort_if($user->id === $authUser->id, 422, 'Tidak bisa nonaktifkan akun sendiri.');

        $newState = !$user->is_active;
        if (!$newState && $user->role === 'super_admin') {
            $this->assertNotLastSuperAdmin($user);
        }
        $user->update(['is_active' => $newState]);

        $status = $newState ? 'diaktifkan' : 'dinonaktifkan';
        return back()->with('success', "User {$user->username} {$status}.");
    }

    /* ============================= Helpers ============================= */

    private function validatedData(Request $request, ?User $existing): array
    {
        $userId = $existing?->id;

        return $request->validate([
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($userId)],
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$existing ? 'nullable' : 'required', 'string', 'min:6', 'max:60'],
            'role'     => ['required', Rule::in(['super_admin', 'admin', 'user', 'karyawan'])],
            'karyawan_id' => ['nullable', 'integer', 'exists:sdm_karyawan,id', Rule::unique('users', 'karyawan_id')->ignore($userId)],
            'permissions'   => 'array',
            'permissions.*' => 'string|max:80',
        ], [
            'karyawan_id.unique' => 'Karyawan ini sudah ter-link ke user lain.',
            'username.unique'    => 'Username sudah dipakai.',
            'email.unique'       => 'Email sudah dipakai.',
        ]);
    }

    /** Admin tidak boleh assign role super_admin atau admin. */
    private function guardRole($authUser, string $targetRole): void
    {
        if ($authUser->isSuperAdmin()) return;
        // Admin hanya boleh role 'user'
        abort_if($targetRole !== 'user', 403, 'Admin hanya boleh mengelola user role "user".');
    }

    /** Admin tidak boleh edit super_admin atau admin lain. */
    private function guardEditTarget($authUser, User $target): void
    {
        if ($authUser->isSuperAdmin()) return;
        if ($target->id === $authUser->id) return; // edit diri sendiri OK
        abort_if($target->role !== 'user', 403, 'Anda tidak punya akses mengelola user role "' . $target->role . '".');
    }

    private function assertNotLastSuperAdmin(User $user): void
    {
        $count = User::where('role', 'super_admin')->where('is_active', true)->where('id', '!=', $user->id)->count();
        abort_if($count === 0, 422, 'Tidak boleh hapus / non-aktifkan / demote super_admin terakhir.');
    }

    private function syncPermissions(User $user, array $keys): void
    {
        $registry = app(MenuRegistry::class);
        $valid = $registry->flatForValidation();
        $keys = array_values(array_intersect($keys, $valid)); // sanitize

        DB::transaction(function () use ($user, $keys) {
            $user->menuPermissions()->delete();
            foreach ($keys as $k) {
                $user->menuPermissions()->create(['menu_key' => $k]);
            }
        });
        $user->clearMenuCache();
    }
}
