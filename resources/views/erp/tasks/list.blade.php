@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Task — Tampilan Daftar</h1>
    <div class="flex gap-2">
        <a href="{{ route('tasks.index') }}" class="text-sm text-gray-600">📋 Tampilan Board</a>
        <a href="{{ route('tasks.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Task</a>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div class="flex-1 min-w-[180px]">
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="search" value="{{ $search }}" placeholder="Judul task..."
               class="w-full border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['open'=>'Terbuka','in_progress'=>'Dikerjakan','done'=>'Selesai','cancelled'=>'Dibatalkan'] as $v=>$lbl)
                <option value="{{ $v }}" @selected($status===$v)>{{ $lbl }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
        <select name="priority" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['high'=>'Tinggi','normal'=>'Normal','low'=>'Rendah'] as $v=>$lbl)
                <option value="{{ $v }}" @selected($priority===$v)>{{ $lbl }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Assignee</label>
        <select name="assignee" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="me" @selected($assignee==='me')>Saya</option>
            @foreach($assignableUsers as $u)
                <option value="{{ $u->id }}" @selected((string)$assignee === (string)$u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Judul</th>
                <th class="px-3 py-2 text-left">Kategori</th>
                <th class="px-3 py-2 text-left">Assignee</th>
                <th class="px-3 py-2 text-center">Prioritas</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-left">Jatuh Tempo</th>
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($tasks as $task)
            @php
                $prio = $task->priorityLabel();
                $stat = $task->statusLabel();
                $isHidden = in_array((int) $task->id, $hiddenTaskIds ?? [], true);
            @endphp
            <tr class="border-b hover:bg-blue-50 cursor-pointer {{ $isHidden ? 'bg-gray-50' : '' }}" onclick="window.location='{{ route('tasks.show', $task->id) }}'">
                <td class="px-3 py-2 font-medium {{ $task->status === 'done' ? 'line-through text-gray-400' : '' }}">
                    {{ $task->title }}
                    @if($isHidden)
                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-gray-200 text-gray-600" title="Disembunyikan dari board Anda">🙈 Disembunyikan</span>
                    @endif
                </td>
                <td class="px-3 py-2">
                    @if($task->category)
                        <span class="px-2 py-0.5 rounded text-xs text-white" style="background: {{ $task->category->color }}">{{ $task->category->name }}</span>
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-3 py-2">{{ $task->assignee->name ?? '—' }}</td>
                <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $prio['class'] }}">{{ $prio['label'] }}</span></td>
                <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $stat['class'] }}">{{ $stat['label'] }}</span></td>
                <td class="px-3 py-2">{{ $task->due_date ? $task->due_date->format('d M Y') : '—' }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                    @if($isHidden)
                        <form method="POST" action="{{ route('tasks.unhide', $task->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="border border-blue-500 text-blue-600 hover:bg-blue-50 px-2 py-1 rounded text-xs" title="Tampilkan kembali di board">👁 Tampilkan</button>
                        </form>
                    @endif
                    @if($task->status === 'done')
                        <form method="POST" action="{{ route('tasks.status', $task->id) }}" class="inline"
                              onsubmit="return confirm('Buka kembali task ini ke status Open?')">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="open">
                            <button type="submit" class="border border-amber-500 text-amber-600 hover:bg-amber-50 px-2 py-1 rounded text-xs">Buka Kembali</button>
                        </form>
                    @endif
                    <a href="{{ route('tasks.edit', $task->id) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada task.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($tasks->hasPages())
    <div class="mt-3">{{ $tasks->links() }}</div>
@endif
@endsection
