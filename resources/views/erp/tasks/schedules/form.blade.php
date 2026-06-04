@extends('layouts.erp')

@section('content')
@php
    $isEdit = $mode === 'edit';
    $action = $isEdit ? route('tasks.schedules.update', $schedule->id) : route('tasks.schedules.store');
    $title  = $isEdit ? 'Edit Jadwal Otomatis' : 'Tambah Jadwal Otomatis';

    $subTemplate = $schedule->subtasks_template ?? [];
    $subTemplateText = is_array($subTemplate) ? implode("\n", $subTemplate) : '';
@endphp

@include('erp.tasks.automation._subtabs', ['current' => 'jadwal'])

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $title }}</h1>
    <a href="{{ route('tasks.schedules.index') }}" class="text-sm text-gray-500">← Kembali</a>
</div>

@if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-3 text-sm">
        <ul class="list-disc pl-5">@foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="bg-white rounded shadow p-4 space-y-4 max-w-3xl">
    @csrf
    @if($isEdit) @method('PATCH') @endif

    <div>
        <label class="block text-xs text-gray-500 mb-1">Judul *</label>
        <input type="text" name="title" value="{{ old('title', $schedule->title) }}" required
               class="w-full border rounded px-3 py-2 text-sm">
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kategori</label>
            <select name="category_id" class="w-full border rounded px-2 py-2 text-sm">
                <option value="">— Tanpa Kategori —</option>
                @foreach($categories as $c)
                    <option value="{{ $c->id }}" @selected(old('category_id', $schedule->category_id)==$c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Ditugaskan ke</label>
            <select name="assignee_user_id" class="w-full border rounded px-2 py-2 text-sm">
                <option value="">— Tidak ditentukan —</option>
                @foreach($assignableUsers as $u)
                    <option value="{{ $u->id }}" @selected(old('assignee_user_id', $schedule->assignee_user_id)==$u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
            <select name="priority" class="w-full border rounded px-2 py-2 text-sm">
                @foreach(['low'=>'Low','normal'=>'Normal','high'=>'High'] as $v=>$lbl)
                    <option value="{{ $v }}" @selected(old('priority', $schedule->priority ?? 'normal')===$v)>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="border-t pt-3">
        <div class="text-xs font-semibold text-gray-600 mb-2">Frekuensi</div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Frekuensi *</label>
                <select name="frequency" id="freqSelect" class="w-full border rounded px-2 py-2 text-sm">
                    @foreach(['daily'=>'Setiap hari','weekly'=>'Mingguan','monthly'=>'Bulanan','custom_cron'=>'Custom cron'] as $v=>$lbl)
                        <option value="{{ $v }}" @selected(old('frequency', $schedule->frequency ?? 'daily')===$v)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div data-freq="daily weekly monthly">
                <label class="block text-xs text-gray-500 mb-1">Jam</label>
                <input type="time" name="time_of_day" value="{{ old('time_of_day', $schedule->time_of_day ? substr($schedule->time_of_day, 0, 5) : '09:00') }}"
                       class="w-full border rounded px-2 py-2 text-sm">
            </div>
            <div data-freq="weekly">
                <label class="block text-xs text-gray-500 mb-1">Hari (mingguan)</label>
                <select name="day_of_week" class="w-full border rounded px-2 py-2 text-sm">
                    @foreach(['0'=>'Minggu','1'=>'Senin','2'=>'Selasa','3'=>'Rabu','4'=>'Kamis','5'=>'Jumat','6'=>'Sabtu'] as $v=>$lbl)
                        <option value="{{ $v }}" @selected((string)old('day_of_week', $schedule->day_of_week)===(string)$v)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div data-freq="monthly">
                <label class="block text-xs text-gray-500 mb-1">Tanggal (bulanan)</label>
                <input type="number" name="day_of_month" min="1" max="31"
                       value="{{ old('day_of_month', $schedule->day_of_month ?? 1) }}"
                       class="w-full border rounded px-2 py-2 text-sm">
            </div>
            <div data-freq="custom_cron" class="md:col-span-3">
                <label class="block text-xs text-gray-500 mb-1">Cron expression</label>
                <input type="text" name="cron_expression" value="{{ old('cron_expression', $schedule->cron_expression) }}"
                       placeholder="Mis. 0 9 * * 1-5 (jam 9 setiap weekday)"
                       class="w-full border rounded px-2 py-2 text-sm font-mono">
            </div>
        </div>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Deskripsi</label>
        <textarea name="description" rows="3" class="w-full border rounded px-3 py-2 text-sm">{{ old('description', $schedule->description) }}</textarea>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Template Subtask (satu per baris)</label>
        <textarea name="subtasks_template" rows="4" placeholder="Contoh:&#10;Cek stok&#10;Lapor admin"
                  class="w-full border rounded px-3 py-2 text-sm font-mono">{{ old('subtasks_template', $subTemplateText) }}</textarea>
        <div class="text-[11px] text-gray-400 mt-1">Setiap baris akan jadi subtask saat task otomatis dibuat dari jadwal ini.</div>
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $schedule->is_active ?? true))>
        Jadwal aktif
    </label>

    <div class="flex gap-2 pt-2 border-t">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-semibold">💾 Simpan</button>
        <a href="{{ route('tasks.schedules.index') }}" class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
    </div>
</form>

<script>
(function() {
    const sel = document.getElementById('freqSelect');
    if (!sel) return;
    const update = () => {
        const v = sel.value;
        document.querySelectorAll('[data-freq]').forEach(el => {
            const allowed = el.dataset.freq.split(' ');
            el.style.display = allowed.includes(v) ? '' : 'none';
        });
    };
    sel.addEventListener('change', update);
    update();
})();
</script>
@endsection
