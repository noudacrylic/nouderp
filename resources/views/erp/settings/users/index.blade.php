@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="userManager()">

    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-800">User &amp; Akses</h1>
            <p class="text-xs text-gray-500 mt-0.5">Kelola user login &amp; centang menu yang boleh diakses. <b>super_admin</b> &amp; <b>admin</b> otomatis akses semua menu — hanya role <b>user</b> yang perlu di-centang.</p>
        </div>
        <button type="button" @click="openCreate()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-bold">
            + Tambah User
        </button>
    </div>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 text-xs">
                    <tr>
                        <th class="px-3 py-2 text-left">Username</th>
                        <th class="px-3 py-2 text-left">Nama</th>
                        <th class="px-3 py-2 text-left">Role</th>
                        <th class="px-3 py-2 text-left">Karyawan</th>
                        <th class="px-3 py-2 text-center">Status</th>
                        <th class="px-3 py-2 text-center">Menu</th>
                        <th class="px-3 py-2 text-left">Last Login</th>
                        <th class="px-3 py-2 text-right w-44">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        @php
                            $roleBadge = [
                                'super_admin' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'label' => 'Super Admin'],
                                'admin'       => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'label' => 'Admin'],
                                'user'        => ['bg' => 'bg-gray-100',   'text' => 'text-gray-700',   'label' => 'User'],
                            ][$user->role];
                            $userJsonPayload = [
                                'id' => $user->id,
                                'username' => $user->username,
                                'name' => $user->name,
                                'email' => $user->email,
                                'role' => $user->role,
                                'karyawan_id' => $user->karyawan_id,
                                'is_active' => (bool) $user->is_active,
                                'permissions' => $user->menuPermissions->pluck('menu_key')->all(),
                            ];
                        @endphp
                        <tr class="border-t">
                            <td class="px-3 py-2 font-mono text-xs">{{ $user->username }}</td>
                            <td class="px-3 py-2 font-medium">{{ $user->name }}</td>
                            <td class="px-3 py-2">
                                <span class="{{ $roleBadge['bg'] }} {{ $roleBadge['text'] }} px-2 py-0.5 rounded text-xs font-semibold">
                                    {{ $roleBadge['label'] }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-600">
                                {{ $user->karyawan ? "{$user->karyawan->name} ({$user->karyawan->staf_code})" : '—' }}
                            </td>
                            <td class="px-3 py-2 text-center">
                                @if($user->is_active)
                                    <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-xs font-semibold">Aktif</span>
                                @else
                                    <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-xs font-semibold">Non-aktif</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center text-xs text-gray-600">
                                @if(in_array($user->role, ['super_admin','admin']))
                                    <span class="text-gray-400">SEMUA</span>
                                @else
                                    {{ $user->menu_permissions_count }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500">
                                {{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="flex gap-1 flex-row-reverse flex-nowrap">
                                    @if($user->id !== $authUser->id)
                                        <form method="POST" action="{{ route('settings.users.destroy', $user->id) }}" class="inline"
                                              onsubmit="return confirm('Hapus user {{ $user->username }}? Tidak bisa di-undo.');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                        </form>
                                        <form method="POST" action="{{ route('settings.users.toggle-active', $user->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="{{ $user->is_active ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-emerald-500 hover:bg-emerald-600' }} text-white px-2 py-1 rounded text-xs">
                                                {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </button>
                                        </form>
                                    @endif
                                    <button type="button"
                                            @click='openEdit(@json($userJsonPayload))'
                                            class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Edit</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-8 text-center text-gray-400 italic">Belum ada user. Klik "Tambah User" untuk membuat.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal Add/Edit --}}
    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
            <div class="absolute inset-0 bg-black/40" @click="closeModal()"></div>

            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <h3 class="font-bold text-gray-800" x-text="form.id ? 'Edit User: ' + form.username : 'Tambah User Baru'"></h3>
                    <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-700 text-xl">&times;</button>
                </div>

                {{-- Tabs --}}
                <div class="px-6 pt-3 border-b flex gap-2">
                    <button type="button"
                            @click="tab = 'profile'"
                            :class="tab === 'profile' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                            class="px-3 py-2 border-b-2 text-sm font-semibold">
                        Profile
                    </button>
                    <button type="button"
                            x-show="form.role === 'user'"
                            @click="tab = 'permissions'"
                            :class="tab === 'permissions' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                            class="px-3 py-2 border-b-2 text-sm font-semibold">
                        Permissions <span x-text="'(' + form.permissions.length + ')'"></span>
                    </button>
                </div>

                <form :action="form.id ? '{{ url('erp/settings/users') }}/' + form.id : '{{ route('settings.users.store') }}'"
                      method="POST" class="overflow-y-auto flex-1">
                    @csrf
                    <template x-if="form.id">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    {{-- TAB PROFILE --}}
                    <div x-show="tab === 'profile'" class="p-6 space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1">Username *</label>
                                <input type="text" name="username" x-model="form.username" required maxlength="60"
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1">Nama Lengkap *</label>
                                <input type="text" name="name" x-model="form.name" required maxlength="100"
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Email (opsional)</label>
                            <input type="email" name="email" x-model="form.email" maxlength="255"
                                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">
                                Password <span x-text="form.id ? '(kosongkan kalau tidak diubah)' : '*'"></span>
                            </label>
                            <input type="password" name="password" :required="!form.id" minlength="6" maxlength="60"
                                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                                   placeholder="Min 6 karakter">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1">Role *</label>
                                <select name="role" x-model="form.role" required
                                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    @if($authUser->isSuperAdmin())
                                        <option value="super_admin">Super Admin (akses penuh)</option>
                                        <option value="admin">Admin (akses penuh + manage user)</option>
                                    @endif
                                    <option value="user">User (per-menu)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1">Link Karyawan (opsional)</label>
                                <select name="karyawan_id" x-model="form.karyawan_id"
                                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    <option value="">— Tidak ter-link —</option>
                                    @foreach($karyawanOptions as $k)
                                        <option value="{{ $k->id }}">{{ $k->name }} ({{ $k->staf_code }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="rounded">
                            <span>Akun aktif (bisa login)</span>
                        </label>
                    </div>

                    {{-- TAB PERMISSIONS --}}
                    <div x-show="tab === 'permissions'" class="p-6 space-y-3">
                        <p class="text-xs text-gray-500 mb-2">Centang menu yang boleh diakses user ini. Menu Dashboard otomatis bisa diakses semua user.</p>

                        @php
                            // Kumpulkan semua leaf keys (termasuk dept-specific) per group untuk "Centang Semua"
                            $groupLeafKeys = [];
                            foreach ($menuTree as $gKey => $gCfg) {
                                $leaves = [];
                                foreach ($gCfg['children'] ?? [] as $cKey => $cCfg) {
                                    $leaves[] = $cKey;
                                    foreach ($cCfg['children'] ?? [] as $ggKey => $ggCfg) {
                                        $leaves[] = $ggKey;
                                    }
                                }
                                $groupLeafKeys[$gKey] = $leaves;
                            }
                        @endphp

                        @foreach($menuTree as $groupKey => $groupCfg)
                            @if(!empty($groupCfg['children']) && empty($groupCfg['role_gate']))
                                <div class="border border-gray-200 rounded-lg overflow-hidden" x-data="{ open: false }">
                                    <div class="bg-gray-50 px-3 py-2 flex items-center justify-between cursor-pointer" @click="open = !open">
                                        <div class="flex items-center gap-2">
                                            <input type="checkbox"
                                                   @click.stop
                                                   :checked="isGroupAllChecked({{ json_encode($groupLeafKeys[$groupKey]) }})"
                                                   @change="toggleGroup({{ json_encode($groupLeafKeys[$groupKey]) }}, $event.target.checked)"
                                                   class="rounded">
                                            <span class="text-sm font-bold text-gray-800">
                                                {{ $groupCfg['icon'] ?? '' }} {{ $groupCfg['label'] }}
                                            </span>
                                            <span class="text-xs text-gray-400" x-text="countGroup({{ json_encode($groupLeafKeys[$groupKey]) }}) + '/' + {{ count($groupLeafKeys[$groupKey]) }}"></span>
                                        </div>
                                        <span class="text-gray-400 text-xs" x-text="open ? '▾' : '▸'"></span>
                                    </div>
                                    <div x-show="open" x-cloak class="px-4 py-2 space-y-1 bg-white">
                                        @foreach($groupCfg['children'] as $childKey => $childCfg)
                                            <div>
                                                <label class="flex items-center gap-2 text-sm text-gray-700 py-1">
                                                    <input type="checkbox" name="permissions[]" value="{{ $childKey }}"
                                                           :checked="form.permissions.includes('{{ $childKey }}')"
                                                           @change="togglePerm('{{ $childKey }}', $event.target.checked)"
                                                           class="rounded">
                                                    <span>{{ $childCfg['label'] }}</span>
                                                    <span class="text-[10px] text-gray-400 font-mono ml-auto">{{ $childKey }}</span>
                                                </label>

                                                {{-- Sub-children (mis. departments di bawah Proses Produksi) --}}
                                                @if(!empty($childCfg['children']))
                                                    <div class="ml-6 pl-3 border-l-2 border-gray-100 space-y-1 mt-1 mb-2">
                                                        <div class="text-[11px] text-gray-400 italic">
                                                            Pilih divisi spesifik (atau centang "{{ $childCfg['label'] }}" di atas untuk SEMUA divisi):
                                                        </div>
                                                        @foreach($childCfg['children'] as $ggKey => $ggCfg)
                                                            <label class="flex items-center gap-2 text-xs text-gray-600 py-0.5">
                                                                <input type="checkbox" name="permissions[]" value="{{ $ggKey }}"
                                                                       :checked="form.permissions.includes('{{ $ggKey }}')"
                                                                       @change="togglePerm('{{ $ggKey }}', $event.target.checked)"
                                                                       class="rounded">
                                                                <span>↳ {{ $ggCfg['label'] }}</span>
                                                                <span class="text-[10px] text-gray-400 font-mono ml-auto">{{ $ggKey }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="px-6 py-3 border-t bg-gray-50 flex gap-2 justify-end">
                        <button type="button" @click="closeModal()" class="border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-gray-50">Batal</button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-sm font-bold">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </template>

</div>

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function userManager() {
    return {
        modalOpen: false,
        tab: 'profile',
        form: { id: null, username: '', name: '', email: '', password: '', role: 'user', karyawan_id: '', is_active: true, permissions: [] },

        openCreate() {
            this.form = { id: null, username: '', name: '', email: '', password: '', role: 'user', karyawan_id: '', is_active: true, permissions: [] };
            this.tab = 'profile';
            this.modalOpen = true;
        },
        openEdit(data) {
            this.form = {
                id: data.id,
                username: data.username,
                name: data.name,
                email: data.email || '',
                password: '',
                role: data.role,
                karyawan_id: data.karyawan_id || '',
                is_active: !!data.is_active,
                permissions: Array.isArray(data.permissions) ? [...data.permissions] : [],
            };
            this.tab = 'profile';
            this.modalOpen = true;
        },
        closeModal() { this.modalOpen = false; },

        togglePerm(key, checked) {
            const i = this.form.permissions.indexOf(key);
            if (checked && i === -1) this.form.permissions.push(key);
            else if (!checked && i !== -1) this.form.permissions.splice(i, 1);
        },
        toggleGroup(keys, checked) {
            for (const k of keys) this.togglePerm(k, checked);
        },
        isGroupAllChecked(keys) {
            return keys.every(k => this.form.permissions.includes(k));
        },
        countGroup(keys) {
            return keys.filter(k => this.form.permissions.includes(k)).length;
        },
    };
}
</script>
@endpush
@endsection
