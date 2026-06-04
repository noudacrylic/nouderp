@extends('layouts.erp')

@section('content')
@php
    $prio = $task->priorityLabel();
    $stat = $task->statusLabel();
    $link = $task->taskableLink();
    $isOverdue = $task->due_date && $task->due_date->isPast() && $task->status !== 'done';
@endphp

<div class="flex items-center justify-between mb-4">
    <a href="{{ route('tasks.index') }}" class="text-sm text-gray-500">← Board</a>
    <div class="flex gap-2">
        <a href="{{ route('tasks.edit', $task->id) }}" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">✎ Edit</a>
    </div>
</div>

@if(session('success'))
    <div class="mb-3 bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm">{{ session('success') }}</div>
@endif

<div class="bg-white rounded shadow p-5 max-w-3xl">
    <div class="flex items-start gap-3 mb-3">
        <form method="POST" action="{{ route('tasks.status', $task->id) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="status" value="{{ $task->status === 'done' ? 'open' : 'done' }}">
            <button type="submit" class="w-7 h-7 rounded-full border-2 border-green-500 text-green-600 hover:bg-green-50">
                {{ $task->status === 'done' ? '✓' : '○' }}
            </button>
        </form>
        <h1 class="text-xl font-semibold flex-1 {{ $task->status === 'done' ? 'line-through text-gray-400' : '' }}">
            {{ $task->title }}
        </h1>
    </div>

    <div class="flex flex-wrap gap-1.5 text-xs mb-4">
        <span class="px-2 py-0.5 rounded {{ $stat['class'] }}">{{ $stat['label'] }}</span>
        <span class="px-2 py-0.5 rounded {{ $prio['class'] }}">{{ $prio['label'] }}</span>
        @if($task->category)
            <span class="px-2 py-0.5 rounded text-white" style="background: {{ $task->category->color }}">{{ $task->category->name }}</span>
        @endif
        @if($task->due_date)
            <span class="px-2 py-0.5 rounded {{ $isOverdue ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700' }}">
                📅 {{ $task->due_date->format('d M Y') }}
            </span>
        @endif
        @if($task->source !== 'manual')
            <span class="px-2 py-0.5 rounded bg-cyan-100 text-cyan-700">
                {{ $task->source === 'scheduled' ? '⏰ Otomatis dari jadwal' : '⚡ Otomatis dari event' }}
            </span>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 text-sm mb-4">
        <div>
            <div class="text-xs text-gray-500">Dibuat oleh</div>
            <div>{{ $task->creator->name ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Ditugaskan ke</div>
            <div>{{ $task->assignee->name ?? '— Tidak ditentukan —' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Dibuat</div>
            <div>{{ $task->created_at->format('d M Y H:i') }}</div>
        </div>
        @if($task->done_at)
        <div>
            <div class="text-xs text-gray-500">Selesai</div>
            <div>{{ $task->done_at->format('d M Y H:i') }}</div>
        </div>
        @endif
    </div>

    @if($link)
        <div class="mb-4 bg-purple-50 border border-purple-200 rounded p-3 text-sm">
            <div class="text-xs text-purple-700 mb-1">🔗 Terkait dengan {{ $link['type'] }}</div>
            <a href="{{ $link['url'] }}" class="text-purple-700 font-semibold hover:underline">{{ $link['label'] }}</a>
        </div>
    @endif

    @if($task->description)
        <div class="mb-4">
            <div class="text-xs text-gray-500 mb-1">Deskripsi</div>
            <div class="text-sm text-gray-800 whitespace-pre-wrap">{{ $task->description }}</div>
        </div>
    @endif

    {{-- Subtasks --}}
    @if($task->subtasks->count() > 0)
        <div class="border-t pt-3">
            <div class="text-xs text-gray-500 mb-2">Checklist</div>
            <div class="space-y-1">
                @foreach($task->subtasks as $sub)
                    <div class="flex items-center gap-2 text-sm">
                        <form method="POST" action="{{ route('tasks.subtasks.toggle', $sub->id) }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="w-5 h-5 rounded border {{ $sub->is_done ? 'bg-green-500 border-green-500 text-white' : 'border-gray-300' }} text-xs">
                                {{ $sub->is_done ? '✓' : '' }}
                            </button>
                        </form>
                        <span class="{{ $sub->is_done ? 'line-through text-gray-400' : '' }}">{{ $sub->title }}</span>
                        <form method="POST" action="{{ route('tasks.subtasks.destroy', $sub->id) }}" class="ml-auto">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Tambah subtask cepat --}}
    <form method="POST" action="{{ route('tasks.subtasks.store', $task->id) }}" class="mt-3 flex gap-2">
        @csrf
        <input type="text" name="title" placeholder="+ Tambah subtask..."
               class="flex-1 border rounded px-2 py-1.5 text-sm" required>
        <button type="submit" class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Tambah</button>
    </form>
</div>
@endsection
