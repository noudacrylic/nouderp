@php
    $prio = $task->priorityLabel();
    $isOverdue = $task->due_date && $task->due_date->isPast() && $task->status !== 'done';
    $isDone = $task->status === 'done';
    $authUser = auth()->user();
    $authId = (int) ($authUser->id ?? 0);
    $canModify = $task->canBeModifiedBy($authUser);
    $isReadOnly = !$canModify;
    $assigneeName = $task->assignee?->name;
@endphp
<div class="task-card {{ $isDone ? 'is-done' : '' }} {{ $isReadOnly ? 'is-readonly' : '' }}"
     draggable="{{ $canModify ? 'true' : 'false' }}"
     data-task-id="{{ $task->id }}"
     data-readonly="{{ $isReadOnly ? '1' : '0' }}">
    {{-- Top row: checkbox + title + (hide/delete) --}}
    <div class="task-card-top">
        <button type="button"
                class="task-check-gt {{ $isDone ? 'checked' : '' }} {{ $isReadOnly ? 'is-disabled' : '' }}"
                data-task-id="{{ $task->id }}"
                @disabled($isReadOnly)
                title="{{ $isReadOnly ? 'Read-only' : ($isDone ? 'Buka kembali' : 'Tandai selesai') }}">
            @if($isDone)<span class="task-check-tick">✓</span>@endif
        </button>

        <div class="task-card-title js-open-detail" data-task-id="{{ $task->id }}">
            {{ $task->title }}
        </div>

        @if($isReadOnly)
            <button type="button" class="task-card-hide js-hide-task"
                    data-task-id="{{ $task->id }}"
                    title="Sembunyikan dari board saya (buka kembali dari Tampilan Daftar)">🚫</button>
        @else
            <button type="button" class="task-card-del js-delete-task"
                    data-task-id="{{ $task->id }}"
                    title="Hapus task">🗑</button>
        @endif
    </div>

    {{-- Label "Diproses oleh" — hanya untuk yang bukan assignee, ketika ada assignee --}}
    @if($assigneeName && $task->assignee_user_id !== $authId)
        <div class="task-card-processed">
            <span class="task-card-processed-icon">👤</span>
            Diproses oleh <strong>{{ $assigneeName }}</strong>
        </div>
    @endif

    {{-- Badges (due date, source) --}}
    @if($task->due_date || $task->source !== 'manual')
        <div class="task-card-badges">
            @if($task->due_date)
                <span class="px-2 py-0.5 rounded text-[11px] {{ $isOverdue ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600' }}">
                    📅 {{ $task->due_date->format('d M') }}
                </span>
            @endif
            @if($task->source === 'scheduled')
                <span class="px-2 py-0.5 rounded text-[11px] bg-cyan-100 text-cyan-700" title="Dari jadwal otomatis">⏰</span>
            @elseif($task->source === 'event')
                <span class="px-2 py-0.5 rounded text-[11px] bg-amber-100 text-amber-700" title="Dari event otomatis">⚡</span>
            @endif
        </div>
    @endif

    {{-- Subtasks --}}
    <div class="task-subtasks" data-task-id="{{ $task->id }}">
        @foreach($task->subtasks as $sub)
            <div class="task-sub-row" data-sub-id="{{ $sub->id }}">
                <button type="button"
                        class="task-sub-check {{ $sub->is_done ? 'checked' : '' }} {{ $isReadOnly ? 'is-disabled' : '' }}"
                        data-sub-id="{{ $sub->id }}"
                        @disabled($isReadOnly)
                        title="{{ $isReadOnly ? 'Read-only' : ($sub->is_done ? 'Buka kembali' : 'Tandai selesai') }}">
                    @if($sub->is_done)<span class="task-check-tick-sm">✓</span>@endif
                </button>
                <span class="task-sub-title {{ $sub->is_done ? 'is-done' : '' }}">{{ $sub->title }}</span>
                @if(!$isReadOnly)
                    <button type="button" class="task-sub-del" data-sub-id="{{ $sub->id }}" title="Hapus subtask">×</button>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Add subtask inline — hanya jika bisa modify --}}
    @if(!$isReadOnly)
        <div class="task-add-sub">
            <button type="button" class="task-add-sub-btn js-show-sub-input" data-task-id="{{ $task->id }}">+ Subtask</button>
            <div class="task-add-sub-input hidden" data-task-id="{{ $task->id }}">
                <input type="text" placeholder="Judul subtask, Enter untuk simpan" class="js-new-sub-input">
            </div>
        </div>
    @endif

    {{-- Footer: assignee + priority dropdown --}}
    <div class="task-card-foot">
        <select class="task-assignee-select" data-task-id="{{ $task->id }}"
                data-current="{{ $task->assignee_user_id }}"
                @disabled($isReadOnly)>
            <option value="">— Tidak ditentukan —</option>
            @foreach(($assignableUsers ?? []) as $u)
                <option value="{{ $u->id }}" @selected($task->assignee_user_id == $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
        <select class="task-priority-select prio-{{ $task->priority }}"
                data-task-id="{{ $task->id }}"
                data-current="{{ $task->priority }}"
                @disabled($isReadOnly)>
            <option value="high" @selected($task->priority==='high')>Tinggi</option>
            <option value="normal" @selected($task->priority==='normal')>Normal</option>
            <option value="low" @selected($task->priority==='low')>Rendah</option>
        </select>
        <button type="button" class="task-detail-btn js-open-detail"
                data-task-id="{{ $task->id }}"
                title="Lihat rincian, deskripsi, dokumen terkait, dan riwayat">Rincian</button>
    </div>
</div>
