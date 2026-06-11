@extends('layouts.erp')

@section('content')
@include('erp.tasks.automation._subtabs', ['current' => 'jadwal'])

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Otomasi Jadwal</h1>
        <p class="text-xs text-gray-500 mt-1">Task otomatis dibuat sesuai jadwal yang ditentukan (harian/mingguan/bulanan/custom cron).</p>
    </div>
    <a href="{{ route('tasks.schedules.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Jadwal</a>
</div>


<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Judul</th>
                <th class="px-3 py-2 text-left">Frekuensi</th>
                <th class="px-3 py-2 text-left">Kategori</th>
                <th class="px-3 py-2 text-left">Assignee</th>
                <th class="px-3 py-2 text-left">Run Berikutnya</th>
                <th class="px-3 py-2 text-center">Aktif</th>
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($schedules as $s)
            <tr class="border-b">
                <td class="px-3 py-2 font-medium">{{ $s->title }}</td>
                <td class="px-3 py-2">
                    {{ $s->frequencyLabel() }}
                    @if($s->time_of_day) <span class="text-gray-400 text-xs">@ {{ substr($s->time_of_day, 0, 5) }}</span>@endif
                </td>
                <td class="px-3 py-2">
                    @if($s->category)
                        <span class="px-2 py-0.5 rounded text-xs text-white" style="background: {{ $s->category->color }}">{{ $s->category->name }}</span>
                    @else — @endif
                </td>
                <td class="px-3 py-2">{{ $s->assignee->name ?? '—' }}</td>
                <td class="px-3 py-2">{{ $s->next_run_at ? $s->next_run_at->format('d M Y H:i') : '—' }}</td>
                <td class="px-3 py-2 text-center">
                    @if($s->is_active)
                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Aktif</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500">Nonaktif</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right">
                    <div class="flex gap-1 flex-row-reverse">
                        <form method="POST" action="{{ route('tasks.schedules.destroy', $s->id) }}" onsubmit="return confirm('Hapus jadwal ini?')">
                            @csrf @method('DELETE')
                            <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                        </form>
                        <a href="{{ route('tasks.schedules.edit', $s->id) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                        <form method="POST" action="{{ route('tasks.schedules.run', $s->id) }}">
                            @csrf
                            <button class="border border-gray-300 text-gray-700 px-2 py-1 rounded text-xs hover:bg-gray-50" title="Buat task sekarang dari jadwal ini">▶ Run sekarang</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada jadwal otomatis.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($schedules->hasPages())
    <div class="mt-3">{{ $schedules->links() }}</div>
@endif
@endsection
