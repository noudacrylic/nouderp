@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4"
     x-data="{ addDept: false, hideInactive: localStorage.getItem('hideInactiveDept') === '1' }"
     x-init="$watch('hideInactive', v => localStorage.setItem('hideInactiveDept', v ? '1' : '0'))">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Departemen Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Master data departemen dan eksekutor untuk proses produksi.</p>
        </div>
        <div class="flex items-center gap-3">
            <label class="inline-flex items-center gap-2 text-xs text-gray-600 cursor-pointer select-none">
                <span class="relative inline-flex">
                    <input type="checkbox" x-model="hideInactive" class="sr-only peer">
                    <span class="w-9 h-5 bg-gray-200 rounded-full peer-checked:bg-emerald-500 transition"></span>
                    <span class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></span>
                </span>
                <span class="font-semibold">Sembunyikan nonaktif</span>
            </label>
            <button @click="addDept = true"
                    class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-blue-100 transition-all active:scale-95">
                + Tambah Departemen
            </button>
        </div>
    </div>


    {{-- Modal Tambah Departemen --}}
    <div x-show="addDept" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
        <div class="absolute inset-0 bg-black/40" @click="addDept = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
            <h3 class="font-bold text-gray-800 mb-4">Tambah Departemen</h3>
            <form action="{{ route('production.departments.store') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Nama Departemen *</label>
                    <input type="text" name="name" required placeholder="Misal: CNC Machining"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                    <div class="text-[11px] text-gray-400 mt-1">Kode dibuat otomatis: <span class="font-mono">PRD-001</span> untuk Produksi, <span class="font-mono">MKT-001</span> untuk Non-Produksi.</div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Tipe *</label>
                    <select name="type" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <option value="produksi">Produksi (muncul di menu Proses Produksi)</option>
                        <option value="non_produksi">Non-Produksi (admin, marketing, dst.)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Deskripsi</label>
                    <textarea name="description" rows="2" placeholder="Keterangan departemen..."
                              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"></textarea>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-xl text-sm font-bold transition">Simpan</button>
                    <button type="button" @click="addDept = false" class="flex-1 border border-gray-200 text-gray-600 py-2 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Batal</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Daftar Departemen --}}
    <div class="space-y-4">
        @forelse($departments as $dept)
            <div x-show="!hideInactive || {{ $dept->is_active ? 'true' : 'false' }}" x-cloak>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden"
                 x-data="{ showMembers: false, showExec: false, addMember: false, addExec: false, execMode: 'karyawan', karyawanList: [], loadingKaryawan: false,
                          loadKaryawan() {
                              if (this.karyawanList.length) return;
                              this.loadingKaryawan = true;
                              fetch('/erp/api/karyawan/except-department/{{ $dept->id }}')
                                  .then(r => r.json())
                                  .then(data => { this.karyawanList = data; this.loadingKaryawan = false; });
                          } }">
                <div class="flex items-center justify-between px-5 py-4">
                    <div class="flex items-center gap-3">
                        <div class="h-10 min-w-[2.5rem] px-3 bg-blue-100 text-blue-700 rounded-xl flex items-center justify-center font-mono font-bold text-xs whitespace-nowrap">
                            {{ $dept->code }}
                        </div>
                        <div>
                            <div class="font-bold text-gray-800">{{ $dept->name }}</div>
                            @if($dept->description)
                                <div class="text-xs text-gray-400">{{ $dept->description }}</div>
                            @endif
                        </div>
                        @if($dept->type === 'non_produksi')
                            <span class="text-[10px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded font-bold">NON-PRODUKSI</span>
                        @else
                            <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-bold">PRODUKSI</span>
                        @endif
                        @if(!$dept->is_active)
                            <span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded font-bold">NONAKTIF</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showMembers = !showMembers"
                                class="text-xs text-emerald-600 hover:text-emerald-700 font-bold px-3 py-1.5 border border-emerald-200 rounded-lg transition">
                            {{ $dept->karyawans->count() }} Anggota
                            <span x-text="showMembers ? '▲' : '▼'" class="ml-1"></span>
                        </button>
                        <button @click="showExec = !showExec"
                                class="text-xs text-blue-600 hover:text-blue-700 font-bold px-3 py-1.5 border border-blue-200 rounded-lg transition">
                            {{ $dept->executors->count() }} Eksekutor
                            <span x-text="showExec ? '▲' : '▼'" class="ml-1"></span>
                        </button>
                        @if($dept->is_active)
                            <form action="{{ route('production.departments.destroy', $dept->id) }}" method="POST"
                                  onsubmit="return confirm('Nonaktifkan departemen ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700 px-2 py-1.5 rounded-lg hover:bg-red-50 transition">Nonaktifkan</button>
                            </form>
                        @else
                            <form action="{{ route('production.departments.activate', $dept->id) }}" method="POST"
                                  onsubmit="return confirm('Aktifkan kembali departemen ini?')">
                                @csrf
                                <button type="submit" class="text-xs text-emerald-600 hover:text-emerald-700 px-2 py-1.5 rounded-lg hover:bg-emerald-50 transition font-bold">Aktifkan</button>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- Anggota Divisi --}}
                <div x-show="showMembers" x-cloak class="border-t border-gray-50 px-5 pb-4 pt-3 bg-emerald-50/30">
                    <div class="flex justify-between items-center mb-3">
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Anggota Divisi</p>
                        <button @click="addMember = !addMember; if(addMember) loadKaryawan();" class="text-xs text-emerald-600 font-bold hover:text-emerald-700">+ Tambah Anggota</button>
                    </div>

                    <div x-show="addMember" x-cloak class="mb-3 p-3 bg-emerald-50 rounded-xl">
                        <form action="{{ route('production.departments.members.add', $dept->id) }}" method="POST" class="flex gap-2">
                            @csrf
                            <select name="karyawan_id" required class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <option value="">— pilih karyawan untuk dipindahkan ke divisi ini —</option>
                                <template x-for="k in karyawanList" :key="k.id">
                                    <option :value="k.id" x-text="k.name + ' (' + k.staf_code + ')' + (k.current_dept ? ' — sekarang di ' + k.current_dept : ' — belum punya divisi')"></option>
                                </template>
                                <option x-show="loadingKaryawan" disabled>Memuat...</option>
                                <option x-show="!loadingKaryawan && karyawanList.length === 0" disabled>Semua karyawan sudah di divisi ini.</option>
                            </select>
                            <button type="submit" class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold">Simpan</button>
                        </form>
                    </div>

                    @if($dept->karyawans->isEmpty())
                        <p class="text-xs text-gray-300 text-center py-2">Belum ada anggota</p>
                    @else
                        <div class="grid grid-cols-2 gap-1">
                            @foreach($dept->karyawans as $k)
                                <div class="flex items-center justify-between py-1.5 px-3 bg-white rounded-lg border border-emerald-100">
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">{{ $k->name }}</span>
                                        <span class="text-[10px] text-gray-400 font-mono ml-1">{{ $k->staf_code }}</span>
                                        @if($k->jabatan)<span class="text-xs text-gray-400 ml-2">{{ $k->jabatan }}</span>@endif
                                    </div>
                                    <form action="{{ route('production.departments.members.remove', [$dept->id, $k->id]) }}" method="POST"
                                          onsubmit="return confirm('Lepas {{ $k->name }} dari divisi {{ $dept->name }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-400 hover:text-red-600 transition">Lepas</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Eksekutor --}}
                <div x-show="showExec" class="border-t border-gray-50 px-5 pb-4 pt-3">
                    <div class="flex justify-between items-center mb-3">
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Eksekutor</p>
                        <button @click="addExec = !addExec" class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah</button>
                    </div>

                    <div x-show="addExec" class="mb-3 p-3 bg-blue-50 rounded-xl">
                        <div class="flex gap-2 mb-2">
                            <button type="button" @click="execMode = 'karyawan'; loadKaryawan()"
                                    :class="execMode === 'karyawan' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border border-gray-200'"
                                    class="text-xs px-3 py-1 rounded-lg font-bold">Pilih Karyawan</button>
                            <button type="button" @click="execMode = 'manual'"
                                    :class="execMode === 'manual' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border border-gray-200'"
                                    class="text-xs px-3 py-1 rounded-lg font-bold">Input Manual (Mesin / Eksternal)</button>
                        </div>
                        <form action="{{ route('production.departments.executors.store', $dept->id) }}" method="POST" class="flex gap-2">
                            @csrf
                            <template x-if="execMode === 'karyawan'">
                                <div class="flex-1 flex flex-col gap-1">
                                    <select name="karyawan_id" required
                                            class="border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400">
                                        <option value="">— pilih karyawan —</option>
                                        <template x-for="k in karyawanList" :key="k.id">
                                            <option :value="k.id"
                                                    x-text="k.name + ' (' + k.staf_code + ')' + (k.current_dept ? ' — sekarang di ' + k.current_dept : ' — belum punya divisi')"></option>
                                        </template>
                                        <option x-show="loadingKaryawan" disabled>Memuat...</option>
                                        <option x-show="!loadingKaryawan && karyawanList.length === 0" disabled>Semua karyawan sudah di divisi ini.</option>
                                    </select>
                                    <span class="text-[10px] text-gray-500">Memilih karyawan akan <strong>memindahkan</strong> divisi-nya ke {{ $dept->name }}.</span>
                                </div>
                            </template>
                            <template x-if="execMode === 'manual'">
                                <input type="text" name="name" required placeholder="Nama eksekutor / mesin"
                                       class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400">
                            </template>
                            <input type="text" name="role" placeholder="Jabatan/role"
                                   class="w-32 border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400">
                            <button type="submit" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold">Simpan</button>
                        </form>
                    </div>

                    @if($dept->executors->isEmpty())
                        <p class="text-xs text-gray-300 text-center py-2">Belum ada eksekutor</p>
                    @else
                        <div class="space-y-1">
                            @foreach($dept->executors as $exec)
                                <div class="flex items-center justify-between py-1.5 px-3 bg-gray-50 rounded-lg">
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">{{ $exec->display_name }}</span>
                                        @if($exec->karyawan_id)
                                            <span class="text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded ml-1 font-bold">karyawan</span>
                                        @else
                                            <span class="text-[9px] bg-gray-200 text-gray-500 px-1.5 py-0.5 rounded ml-1 font-bold">manual</span>
                                        @endif
                                        @if($exec->role)
                                            <span class="text-xs text-gray-400 ml-2">{{ $exec->role }}</span>
                                        @endif
                                        @if(!$exec->is_active)
                                            <span class="text-[9px] bg-red-100 text-red-500 px-1.5 py-0.5 rounded ml-1">nonaktif</span>
                                        @endif
                                    </div>
                                    <form action="{{ route('production.departments.executors.destroy', [$dept->id, $exec->id]) }}" method="POST"
                                          onsubmit="return confirm('Hapus eksekutor ini?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-400 hover:text-red-600 transition">Hapus</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            </div>
        @empty
            <div class="text-center py-16 text-gray-400">
                <div class="font-bold text-base mb-2">Belum ada departemen</div>
                <p class="text-xs">Tambahkan departemen produksi pertama (CNC, Assembling, Printing, dll.)</p>
            </div>
        @endforelse
    </div>

</div>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush
