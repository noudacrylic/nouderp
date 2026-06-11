@extends('layouts.erp')

@section('content')
@php
    $isEdit = $mode === 'edit';
    $action = $isEdit ? route('tasks.update', $task->id) : route('tasks.store');
    $title  = $isEdit ? 'Edit Task' : 'Tambah Task';
@endphp

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $title }}</h1>
    <a href="{{ url()->previous() }}" class="text-sm text-gray-500">← Kembali</a>
</div>


<form method="POST" action="{{ $action }}" class="bg-white rounded shadow p-4 space-y-4">
    @csrf
    @if($isEdit) @method('PATCH') @endif

    <div>
        <label class="block text-xs text-gray-500 mb-1">Judul *</label>
        <input type="text" name="title" value="{{ old('title', $task->title) }}" required
               class="w-full border rounded px-3 py-2 text-sm">
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kategori</label>
            <select name="category_id" class="w-full border rounded px-2 py-2 text-sm">
                <option value="">— Tanpa Kategori —</option>
                @foreach($categories as $c)
                    <option value="{{ $c->id }}" @selected(old('category_id', $task->category_id)==$c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Ditugaskan ke</label>
            <select name="assignee_user_id" class="w-full border rounded px-2 py-2 text-sm">
                <option value="">— Tidak ditentukan —</option>
                @foreach($assignableUsers as $u)
                    <option value="{{ $u->id }}" @selected(old('assignee_user_id', $task->assignee_user_id)==$u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
            <select name="priority" class="w-full border rounded px-2 py-2 text-sm">
                @foreach(['low'=>'Low','normal'=>'Normal','high'=>'High'] as $v=>$lbl)
                    <option value="{{ $v }}" @selected(old('priority', $task->priority ?? 'normal')===$v)>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="w-full border rounded px-2 py-2 text-sm">
                @foreach(['open'=>'Open','in_progress'=>'In Progress','done'=>'Selesai','cancelled'=>'Dibatalkan'] as $v=>$lbl)
                    <option value="{{ $v }}" @selected(old('status', $task->status ?? 'open')===$v)>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Jatuh tempo</label>
            <input type="date" name="due_date"
                   value="{{ old('due_date', optional($task->due_date)->format('Y-m-d')) }}"
                   class="w-full border rounded px-2 py-2 text-sm">
        </div>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Deskripsi</label>
        <textarea name="description" rows="4" class="w-full border rounded px-3 py-2 text-sm">{{ old('description', $task->description) }}</textarea>
    </div>

    {{-- Link ke dokumen --}}
    <div class="border-t pt-3">
        <div class="text-xs font-semibold text-gray-600 mb-2">Link ke Dokumen (opsional)</div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tipe dokumen</label>
                <select name="taskable_type" class="w-full border rounded px-2 py-2 text-sm">
                    <option value="">— Tidak ada —</option>
                    @foreach($taskableTypes as $cls => $cfg)
                        <option value="{{ $cls }}" @selected(old('taskable_type', $task->taskable_type)===$cls)>{{ $cfg['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">ID Dokumen</label>
                <input type="number" name="taskable_id" min="1"
                       value="{{ old('taskable_id', $task->taskable_id) }}"
                       class="w-full border rounded px-2 py-2 text-sm" placeholder="Contoh: 42">
                <div class="text-[11px] text-gray-400 mt-1">Buka dokumen tujuan dulu untuk lihat ID di URL.</div>
            </div>
        </div>
    </div>

    {{-- Subtasks --}}
    <div class="border-t pt-3">
        <div class="flex items-center justify-between mb-2">
            <div class="text-xs font-semibold text-gray-600">Subtask (Checklist)</div>
            <button type="button" id="addSubtaskBtn" class="text-xs text-blue-600 hover:underline">+ Tambah baris</button>
        </div>
        <div id="subtasksBody" class="space-y-1.5">
            @php $existingSubs = $task->subtasks ?? collect(); @endphp
            @foreach($existingSubs as $idx => $sub)
                <div class="subtask-row flex items-center gap-2">
                    <input type="hidden" name="subtasks[{{ $idx }}][id]" value="{{ $sub->id }}">
                    <input type="checkbox" name="subtasks[{{ $idx }}][is_done]" value="1" @checked($sub->is_done) class="subtask-check">
                    <input type="text" name="subtasks[{{ $idx }}][title]" value="{{ $sub->title }}"
                           class="flex-1 border rounded px-2 py-1.5 text-sm">
                    <button type="button" class="text-red-500 text-xs remove-subtask">✕</button>
                </div>
            @endforeach
        </div>
    </div>

    <div class="flex gap-2 pt-2 border-t">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-semibold">
            💾 Simpan
        </button>
        <a href="{{ $isEdit ? route('tasks.show', $task->id) : route('tasks.index') }}"
           class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm">
            Batal
        </a>
        @if($isEdit)
            <form method="POST" action="{{ route('tasks.destroy', $task->id) }}"
                  class="ml-auto" onsubmit="return confirm('Hapus task ini?')">
                @csrf @method('DELETE')
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded text-sm">Hapus</button>
            </form>
        @endif
    </div>
</form>

<script>
(function() {
    const body = document.getElementById('subtasksBody');
    const btn = document.getElementById('addSubtaskBtn');
    if (!body || !btn) return;

    btn.addEventListener('click', () => {
        const idx = body.querySelectorAll('.subtask-row').length;
        const row = document.createElement('div');
        row.className = 'subtask-row flex items-center gap-2';
        row.innerHTML = `
            <input type="checkbox" name="subtasks[${idx}][is_done]" value="1" class="subtask-check">
            <input type="text" name="subtasks[${idx}][title]" placeholder="Judul subtask..."
                   class="flex-1 border rounded px-2 py-1.5 text-sm">
            <button type="button" class="text-red-500 text-xs remove-subtask">✕</button>
        `;
        body.appendChild(row);
        row.querySelector('input[type=text]').focus();
    });

    body.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-subtask')) {
            e.target.closest('.subtask-row').remove();
        }
    });
})();
</script>
@endsection
