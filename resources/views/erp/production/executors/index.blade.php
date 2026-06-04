@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Eksekutor Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Jadwal kerja eksekutor mengikuti jadwal karyawan (Edit Karyawan → Jadwal Kerja). Timer produksi auto-start saat karyawan scan check-in &amp; auto-pause saat scan pulang. Mesin (tanpa fingerprint) bisa diberi <b>parent operator</b> agar ikut scan operatornya.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
    @endif

    @if(!$department)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8 text-center text-gray-400 text-sm">
            Belum ada divisi produksi. Tambahkan di menu <a href="{{ route('production.departments.index') }}" class="text-blue-600 hover:underline">Divisi</a>.
        </div>
    @else
        @php
            $dept = $department;
            $execKaryawanIds = $dept->executors->pluck('karyawan_id')->filter()->all();
            $countAnggota = $dept->karyawans->count();
            $countEksekutor = $dept->executors->count();
        @endphp

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" x-data="{ addManual: false }">
            <div class="px-5 py-3 flex items-center justify-between bg-gray-50 border-b">
                <div class="flex items-center gap-3">
                    <span class="font-mono text-xs bg-gray-200 text-gray-700 px-2 py-0.5 rounded">{{ $dept->code }}</span>
                    <h2 class="font-bold text-gray-800">{{ $dept->name }}</h2>
                    <span class="text-xs text-gray-500">{{ $countAnggota }} karyawan · {{ $countEksekutor }} eksekutor</span>
                </div>
            </div>

            <div class="p-5 space-y-5">

                    {{-- KARYAWAN DI DIVISI INI --}}
                    <div>
                        <div class="text-xs font-bold text-gray-500 uppercase mb-2">Karyawan di divisi ini</div>
                        @if($countAnggota === 0)
                            <div class="text-xs text-gray-400 italic">Belum ada karyawan di divisi ini. Set divisi karyawan di menu <a href="{{ route('sdm.karyawan.index') }}" class="text-blue-600 hover:underline">Karyawan</a>.</div>
                        @else
                            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600 text-xs">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Staf Code</th>
                                            <th class="px-3 py-2 text-left">Nama</th>
                                            <th class="px-3 py-2 text-left">Jabatan</th>
                                            <th class="px-3 py-2 text-center w-32">Status Eksekutor</th>
                                            <th class="px-3 py-2 text-right w-44">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dept->karyawans as $k)
                                            @php $isExec = in_array($k->id, $execKaryawanIds, true); @endphp
                                            <tr class="border-t">
                                                <td class="px-3 py-2 font-mono text-xs">{{ $k->staf_code }}</td>
                                                <td class="px-3 py-2 font-medium">{{ $k->name }}</td>
                                                <td class="px-3 py-2 text-gray-600">{{ $k->jabatan ?? '-' }}</td>
                                                <td class="px-3 py-2 text-center">
                                                    @if($isExec)
                                                        <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-xs font-semibold">Eksekutor</span>
                                                    @else
                                                        <span class="text-gray-400 text-xs">—</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    @if(!$isExec)
                                                        <form method="POST" action="{{ route('production.executors.store') }}" class="inline">
                                                            @csrf
                                                            <input type="hidden" name="department_id" value="{{ $dept->id }}">
                                                            <input type="hidden" name="karyawan_id" value="{{ $k->id }}">
                                                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded text-xs">+ Jadikan Eksekutor</button>
                                                        </form>
                                                    @else
                                                        <span class="text-xs text-gray-400">Atur di tabel bawah</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- EKSEKUTOR TERDAFTAR --}}
                    <div>
                        <div class="text-xs font-bold text-gray-500 uppercase mb-2">Eksekutor terdaftar</div>
                        @if($countEksekutor === 0)
                            <div class="text-xs text-gray-400 italic">Belum ada eksekutor.</div>
                        @else
                            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600 text-xs">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Nama</th>
                                            <th class="px-3 py-2 text-left">Parent</th>
                                            <th class="px-3 py-2 text-left">Role</th>
                                            <th class="px-3 py-2 text-left">Mesin</th>
                                            <th class="px-3 py-2 text-left">Jadwal Hari Ini</th>
                                            <th class="px-3 py-2 text-left">Scan Hari Ini</th>
                                            <th class="px-3 py-2 text-center">Lembur</th>
                                            <th class="px-3 py-2 text-right w-32">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php
                                            // Sort: parent dulu, lalu children (parent_executor_id, id).
                                            // Render dengan indentasi visual untuk hierarki.
                                            $sorted = $dept->executors->sortBy(function($e) {
                                                return [$e->parent_executor_id ?? 0, $e->id];
                                            })->values();
                                        @endphp
                                        @foreach($sorted as $exec)
                                            @php
                                                $info = $execInfo[$exec->id] ?? null;
                                                $sched = $info['schedule'] ?? null;
                                                $checkIn = $info['check_in'] ?? null;
                                                $checkOut = $info['check_out'] ?? null;
                                                $overtimeIn = $info['overtime_in'] ?? null;
                                                $status = $info['status'] ?? 'no_schedule';
                                                $effKaryawanId = $info['effective_karyawan_id'] ?? null;
                                                $mesinList = array_filter([$exec->mesin_1, $exec->mesin_2, $exec->mesin_3]);
                                                $isChild = (bool) $exec->parent_executor_id;
                                            @endphp
                                            <tr class="border-t" x-data="{ edit: false }">
                                                {{-- Nama (dengan indent untuk child) --}}
                                                <td class="px-3 py-2">
                                                    @if($isChild)
                                                        <span class="text-gray-400 mr-1">↳</span>
                                                    @endif
                                                    <span class="font-medium">{{ $exec->karyawan?->name ?? $exec->name }}</span>
                                                    @if(!$exec->karyawan_id)
                                                        <span class="ml-1 bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded text-[10px]">Manual</span>
                                                    @endif
                                                </td>
                                                {{-- Parent --}}
                                                <td class="px-3 py-2 text-xs text-gray-600">
                                                    {{ $exec->parent?->display_name ?: '—' }}
                                                </td>
                                                {{-- Role --}}
                                                <td class="px-3 py-2 text-xs text-gray-600">{{ $exec->role ?: '-' }}</td>
                                                {{-- Mesin --}}
                                                <td class="px-3 py-2 text-xs text-gray-600">
                                                    {{ count($mesinList) ? implode(', ', $mesinList) : '-' }}
                                                </td>
                                                {{-- Jadwal Hari Ini --}}
                                                <td class="px-3 py-2 text-xs">
                                                    @if(!$effKaryawanId)
                                                        <span class="text-red-600 italic">Tidak ter-link karyawan</span>
                                                    @elseif(!$sched)
                                                        <span class="text-gray-400">Tidak ada jadwal</span>
                                                    @elseif($sched->is_off)
                                                        <span class="bg-gray-200 text-gray-600 px-2 py-0.5 rounded font-semibold">LIBUR</span>
                                                    @else
                                                        <span class="text-gray-700">
                                                            {{ \Illuminate\Support\Str::substr($sched->jam_masuk, 0, 5) }}
                                                            – {{ \Illuminate\Support\Str::substr($sched->jam_pulang, 0, 5) }}
                                                        </span>
                                                    @endif
                                                </td>
                                                {{-- Scan Hari Ini --}}
                                                <td class="px-3 py-2 text-xs">
                                                    @if(!$effKaryawanId)
                                                        <span class="text-gray-400">—</span>
                                                    @elseif($checkOut)
                                                        <span class="bg-gray-200 text-gray-700 px-2 py-0.5 rounded">
                                                            ✓ Pulang {{ $checkOut->format('H:i') }}
                                                        </span>
                                                        @if($overtimeIn && $overtimeIn->gt($checkOut))
                                                            <span class="ml-1 bg-purple-100 text-purple-700 px-2 py-0.5 rounded">
                                                                ⏰ Lembur {{ $overtimeIn->format('H:i') }}
                                                            </span>
                                                        @endif
                                                    @elseif($overtimeIn)
                                                        <span class="bg-purple-100 text-purple-700 px-2 py-0.5 rounded">
                                                            ⏰ Lembur {{ $overtimeIn->format('H:i') }}
                                                        </span>
                                                    @elseif($checkIn)
                                                        <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded">
                                                            ✓ Hadir {{ $checkIn->format('H:i') }}
                                                        </span>
                                                    @else
                                                        <span class="bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded">Belum scan</span>
                                                    @endif
                                                </td>
                                                {{-- Lembur --}}
                                                <td class="px-3 py-2 text-center text-xs">
                                                    @if($sched && $sched->has_lembur)
                                                        <span class="bg-purple-100 text-purple-700 px-2 py-0.5 rounded font-semibold">ON</span>
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                                {{-- Aksi --}}
                                                <td class="px-3 py-2 text-right">
                                                    <div class="flex gap-1 flex-row-reverse flex-nowrap">
                                                        <form method="POST" action="{{ route('production.executors.destroy', $exec->id) }}" class="inline"
                                                              onsubmit="return confirm('Hapus eksekutor {{ $exec->karyawan?->name ?? $exec->name }}?')">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                                        </form>
                                                        <button type="button" @click="edit = true" class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Edit</button>
                                                    </div>
                                                </td>

                                                {{-- Modal edit eksekutor --}}
                                                <template x-teleport="body">
                                                    <div x-show="edit" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
                                                        <div class="absolute inset-0 bg-black/40" @click="edit = false"></div>
                                                        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
                                                            <h3 class="font-bold text-gray-800 mb-4">Edit Eksekutor: {{ $exec->karyawan?->name ?? $exec->name }}</h3>
                                                            <form method="POST" action="{{ route('production.executors.update', $exec->id) }}" class="space-y-3">
                                                                @csrf
                                                                @method('PUT')
                                                                @if(!$exec->karyawan_id)
                                                                    <div>
                                                                        <label class="block text-xs font-bold text-gray-500 mb-1">Nama</label>
                                                                        <input type="text" name="name" value="{{ $exec->name }}" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                                    </div>
                                                                @endif
                                                                <div>
                                                                    <label class="block text-xs font-bold text-gray-500 mb-1">Parent (Operator yang scan-nya akan diikuti)</label>
                                                                    <select name="parent_executor_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                                        <option value="">— Tidak ada (standalone) —</option>
                                                                        @foreach($dept->executors as $other)
                                                                            @if($other->id !== $exec->id)
                                                                                <option value="{{ $other->id }}" {{ (int) $exec->parent_executor_id === $other->id ? 'selected' : '' }}>
                                                                                    {{ $other->display_name }}{{ $other->karyawan_id ? '' : ' (manual)' }}
                                                                                </option>
                                                                            @endif
                                                                        @endforeach
                                                                    </select>
                                                                    <p class="text-[11px] text-gray-500 mt-1">Pilih parent jika eksekutor ini adalah mesin yang tidak punya scan fingerprint sendiri.</p>
                                                                </div>
                                                                <div>
                                                                    <label class="block text-xs font-bold text-gray-500 mb-1">Role</label>
                                                                    <input type="text" name="role" value="{{ $exec->role }}" maxlength="100" placeholder="Misal: Operator, Mesin CNC" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                                </div>
                                                                <div class="grid grid-cols-3 gap-2">
                                                                    <div>
                                                                        <label class="block text-xs font-bold text-gray-500 mb-1">Mesin 1</label>
                                                                        <input type="text" name="mesin_1" value="{{ $exec->mesin_1 }}" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                                    </div>
                                                                    <div>
                                                                        <label class="block text-xs font-bold text-gray-500 mb-1">Mesin 2</label>
                                                                        <input type="text" name="mesin_2" value="{{ $exec->mesin_2 }}" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                                    </div>
                                                                    <div>
                                                                        <label class="block text-xs font-bold text-gray-500 mb-1">Mesin 3</label>
                                                                        <input type="text" name="mesin_3" value="{{ $exec->mesin_3 }}" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                                    </div>
                                                                </div>
                                                                <div class="flex gap-2 pt-2">
                                                                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-xl text-sm font-bold transition">Simpan</button>
                                                                    <button type="button" @click="edit = false" class="flex-1 border border-gray-200 text-gray-600 py-2 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Batal</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </template>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- TAMBAH EKSEKUTOR NON-KARYAWAN --}}
                    <div class="border-t pt-4">
                        <button type="button" @click="addManual = !addManual" class="text-sm text-blue-600 hover:text-blue-800 font-semibold">
                            <span x-show="!addManual">+ Tambah Eksekutor Non-Karyawan / Mesin</span>
                            <span x-show="addManual" x-cloak>− Tutup</span>
                        </button>
                        <div x-show="addManual" x-cloak class="mt-3 bg-gray-50 rounded-lg p-4">
                            <form method="POST" action="{{ route('production.executors.store') }}" class="space-y-3">
                                @csrf
                                <input type="hidden" name="department_id" value="{{ $dept->id }}">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 mb-1">Nama *</label>
                                        <input type="text" name="name" required maxlength="100" placeholder="Misal: Mesin CNC 1" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 mb-1">Role</label>
                                        <input type="text" name="role" maxlength="100" placeholder="Misal: Mesin, Eksternal" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Parent Eksekutor (Operator)</label>
                                    <select name="parent_executor_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                        <option value="">— Tidak ada —</option>
                                        @foreach($dept->executors as $other)
                                            <option value="{{ $other->id }}">{{ $other->display_name }}{{ $other->karyawan_id ? '' : ' (manual)' }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-[11px] text-gray-500 mt-1">Pilih parent agar mesin ini ikut scan operator (mesin tidak punya fingerprint).</p>
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 mb-1">Mesin 1</label>
                                        <input type="text" name="mesin_1" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 mb-1">Mesin 2</label>
                                        <input type="text" name="mesin_2" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 mb-1">Mesin 3</label>
                                        <input type="text" name="mesin_3" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    </div>
                                </div>
                                <div class="flex gap-2 pt-1">
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold">Simpan</button>
                                    <button type="button" @click="addManual = false" class="border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-gray-50">Batal</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
    @endif
</div>

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush
@endsection
