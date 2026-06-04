@extends('layouts.erp')

@section('content')
<style>
    .task-board {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 8px;
        align-items: flex-start;
    }
    .task-column {
        width: 330px;
        flex-shrink: 0;
        background: #f1f5f9;
        border-radius: 10px;
        padding: 0;
        max-height: calc(100vh - 160px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition: opacity 0.15s;
    }
    .task-column { position: relative; }
    .task-column.col-dragging { opacity: 0.4; }
    .task-column.col-drop-before::before,
    .task-column.col-drop-after::after {
        content: '';
        position: absolute;
        top: 4px;
        bottom: 4px;
        width: 3px;
        background: #2563eb;
        border-radius: 2px;
        pointer-events: none;
        box-shadow: 0 0 4px rgba(37, 99, 235, 0.6);
    }
    .task-column.col-drop-before::before { left: -7px; }
    .task-column.col-drop-after::after { right: -7px; }

    .task-column-header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        cursor: grab;
        user-select: none;
    }
    .task-column-header:active { cursor: grabbing; }
    .task-column-title {
        font-weight: 700;
        font-size: 14px;
        flex: 1;
        text-shadow: 0 1px 2px rgba(0,0,0,0.08);
    }
    .task-column-count {
        font-size: 12px;
        font-weight: 600;
        background: rgba(255,255,255,0.35);
        padding: 1px 8px;
        border-radius: 999px;
        backdrop-filter: blur(2px);
    }
    .task-column-edit {
        font-size: 13px;
        line-height: 1;
        padding: 2px 6px;
        border-radius: 4px;
        background: rgba(255,255,255,0.2);
        cursor: pointer;
        opacity: 0.7;
        transition: opacity 0.15s, background 0.15s;
    }
    .task-column-edit:hover { opacity: 1; background: rgba(255,255,255,0.45); }
    /* Task body pakai CSS Grid auto-fill — sub-kolom otomatis nambah saat
       kolom dilebarin (drag handle kanan). Single-column saat sempit, multi-column saat lebar. */
    .task-column-body {
        flex: 1;
        overflow-y: auto;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 8px;
        min-height: 40px;
        padding: 8px 8px 4px 8px;
        align-content: start;
    }
    .task-column-body.drag-over {
        background: #e0e7ff;
        border-radius: 6px;
    }
    /* Drag-handle untuk resize lebar kolom — muncul saat hover kolom */
    .task-col-resize {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        width: 6px;
        cursor: col-resize;
        z-index: 5;
        opacity: 0;
        transition: opacity 0.15s, background 0.15s;
    }
    .task-column:hover .task-col-resize { opacity: 0.6; }
    .task-col-resize:hover { background: rgba(37, 99, 235, 0.45); opacity: 1; }
    .task-col-resize.is-resizing { background: rgba(37, 99, 235, 0.6); opacity: 1; }
    body.task-col-resizing { cursor: col-resize !important; user-select: none; }
    .task-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px 14px;
        cursor: grab;
        transition: box-shadow 0.15s, transform 0.15s;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .task-card:hover {
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .task-card.dragging {
        opacity: 0.4;
    }
    .task-card.is-done .task-card-title {
        text-decoration: line-through;
        color: #94a3b8;
    }

    /* Top row: checkbox + title + delete */
    .task-card-top {
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }
    .task-card-title {
        flex: 1;
        font-size: 14.5px;
        font-weight: 600;
        color: #1e293b;
        line-height: 1.4;
        cursor: pointer;
        word-break: break-word;
    }
    .task-card-title:hover { color: #2563eb; }
    .task-card-del {
        opacity: 0;
        background: transparent;
        border: none;
        cursor: pointer;
        color: #94a3b8;
        font-size: 14px;
        line-height: 1;
        padding: 0 2px;
        flex-shrink: 0;
        transition: opacity 0.15s, color 0.15s;
    }
    .task-card:hover .task-card-del { opacity: 1; }
    .task-card-del:hover { color: #dc2626; }

    /* Hide / unhide button (untuk task yang assignee orang lain) */
    .task-card-hide {
        opacity: 0.7;
        background: transparent;
        border: none;
        cursor: pointer;
        color: #64748b;
        font-size: 15px;
        line-height: 1;
        padding: 2px 4px;
        flex-shrink: 0;
        border-radius: 4px;
        transition: opacity 0.15s, color 0.15s, background 0.15s;
    }
    .task-card-hide:hover { opacity: 1; color: #2563eb; background: #eff6ff; }

    /* Label "Diproses oleh [nama]" */
    .task-card-processed {
        padding-left: 30px;
        font-size: 11.5px;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 4px;
        font-style: italic;
    }
    .task-card-processed strong { color: #1e293b; font-weight: 600; font-style: normal; }
    .task-card-processed-icon { font-size: 11px; opacity: 0.7; }

    /* Read-only state: dim card sedikit + disable interaksi inline controls */
    .task-card.is-readonly { background: #f8fafc; }
    .task-card.is-readonly .task-card-title { cursor: default; }
    .task-card.is-readonly .task-check-gt.is-disabled,
    .task-card.is-readonly .task-sub-check.is-disabled {
        cursor: not-allowed;
        opacity: 0.55;
    }
    .task-card.is-readonly .task-assignee-select[disabled],
    .task-card.is-readonly .task-priority-select[disabled] {
        cursor: default;
        opacity: 0.75;
        background: transparent !important;
        border-color: transparent !important;
    }

    /* Google Tasks-style checkbox */
    .task-check-gt {
        width: 22px; height: 22px;
        border: 2px solid #94a3b8;
        border-radius: 50%;
        background: white;
        cursor: pointer;
        padding: 0;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 1px;
        transition: border-color 0.15s, background 0.15s;
    }
    .task-check-gt:hover { border-color: #16a34a; }
    .task-check-gt.checked {
        background: #16a34a;
        border-color: #16a34a;
    }
    .task-check-tick { color: white; font-size: 14px; line-height: 1; font-weight: bold; }

    .task-card-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        padding-left: 30px;
    }

    /* Subtasks */
    .task-subtasks { padding-left: 30px; display: flex; flex-direction: column; gap: 3px; }
    .task-sub-row {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #475569;
        padding: 2px 0;
    }
    .task-sub-check {
        width: 16px; height: 16px;
        border: 1.5px solid #cbd5e1;
        border-radius: 4px;
        background: white;
        cursor: pointer;
        padding: 0;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .task-sub-check.checked { background: #16a34a; border-color: #16a34a; }
    .task-check-tick-sm { color: white; font-size: 12px; line-height: 1; font-weight: bold; }
    .task-sub-title { flex: 1; word-break: break-word; }
    .task-sub-title.is-done { text-decoration: line-through; color: #94a3b8; }
    .task-sub-del {
        background: transparent; border: none; cursor: pointer;
        color: #cbd5e1; font-size: 14px; line-height: 1;
        padding: 0 2px; opacity: 0;
        transition: opacity 0.15s, color 0.15s;
    }
    .task-sub-row:hover .task-sub-del { opacity: 1; }
    .task-sub-del:hover { color: #dc2626; }

    /* Add subtask */
    .task-add-sub { padding-left: 30px; }
    .task-add-sub-btn {
        background: transparent; border: none; cursor: pointer;
        color: #94a3b8; font-size: 12px; padding: 2px 0;
    }
    .task-add-sub-btn:hover { color: #2563eb; }
    .task-add-sub-input input {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        padding: 4px 8px;
        font-size: 13px;
    }
    .task-add-sub-input input:focus { outline: none; border-color: #2563eb; }

    /* Footer: assignee + priority dropdowns */
    .task-card-foot {
        padding-left: 30px;
        padding-top: 4px;
        display: flex;
        gap: 6px;
        align-items: center;
        flex-wrap: wrap;
    }
    .task-assignee-select, .task-priority-select {
        font-size: 12px;
        border: 1px solid transparent;
        background: transparent;
        padding: 2px 6px;
        border-radius: 4px;
        cursor: pointer;
        max-width: 100%;
    }
    .task-assignee-select { color: #64748b; flex: 1; min-width: 0; }
    .task-priority-select { font-weight: 600; }
    .task-priority-select.prio-high { color: #dc2626; background: #fee2e2; }
    .task-priority-select.prio-normal { color: #1d4ed8; background: #dbeafe; }
    .task-priority-select.prio-low { color: #475569; background: #e2e8f0; }
    .task-assignee-select:hover { background: #f1f5f9; border-color: #e2e8f0; }
    .task-assignee-select:focus, .task-priority-select:focus { outline: none; border-color: #2563eb; background: white; }
    /* Tombol Detail — visual selaras dgn dropdown priority di sebelahnya */
    .task-detail-btn {
        font-size: 12px;
        font-weight: 600;
        border: 1px solid transparent;
        background: #eff6ff;
        color: #1d4ed8;
        padding: 2px 8px;
        border-radius: 4px;
        cursor: pointer;
        line-height: 1.6;
    }
    .task-detail-btn:hover { background: #dbeafe; border-color: #bfdbfe; }
    .task-detail-btn:focus { outline: none; border-color: #2563eb; background: white; }
    .task-add-btn {
        margin-top: 8px;
        padding: 6px;
        background: white;
        border: 1px dashed #cbd5e1;
        border-radius: 6px;
        font-size: 12px;
        color: #475569;
        cursor: pointer;
        text-decoration: none;
        text-align: center;
    }
    .task-add-btn:hover { border-color: #2563eb; color: #2563eb; }
    /* Tombol "+ Tambah" sekarang grid item di dalam .task-column-body — gap kolom sudah
       memberi spacing, jadi margin-top default-nya di-nolkan. */
    .task-column-body > .task-add-btn { margin-top: 0; }
    .task-add-column {
        width: 240px;
        flex-shrink: 0;
        background: rgba(255,255,255,0.6);
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 16px;
        text-align: center;
        color: #475569;
        text-decoration: none;
        align-self: flex-start;
    }
    .task-add-column:hover { background: white; border-color: #2563eb; color: #2563eb; }

    /* === Modal (prefix 'tm-' supaya tidak konflik dengan Bootstrap .modal-backdrop) === */
    .tm-modal {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.45);
        z-index: 1100;
        display: flex !important; align-items: flex-start; justify-content: center;
        padding-top: 60px;
    }
    .tm-modal.tm-hidden { display: none !important; }
    .tm-modal-card {
        background: white; border-radius: 10px;
        width: 100%; max-width: 640px;
        max-height: calc(100vh - 100px);
        display: flex; flex-direction: column;
        box-shadow: 0 10px 25px rgba(0,0,0,0.18);
        margin: 0 16px;
    }
    .tm-modal-card.small { max-width: 460px; }
    .tm-modal-head {
        padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: space-between;
    }
    .tm-modal-body { padding: 14px 16px; overflow-y: auto; }
    .tm-modal-foot {
        padding: 12px 16px;
        border-top: 1px solid #e2e8f0;
        display: flex; gap: 8px; justify-content: flex-end;
    }
    .tm-modal-close {
        background: transparent; border: none; cursor: pointer;
        font-size: 18px; color: #64748b;
    }
    .tm-modal-close:hover { color: #0f172a; }
</style>

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
    <h1 class="text-lg font-semibold">Task Manager</h1>
    <div class="flex gap-2 items-center relative">
        <button type="button" id="btnToggleColsPopover"
                class="text-sm text-gray-700 border border-gray-300 hover:border-gray-500 px-3 py-2 rounded">
            👁 Kolom Saya
        </button>
        <a href="{{ route('tasks.list') }}" class="text-sm text-gray-600 hover:text-gray-900">📋 Tampilan Daftar</a>
        <button type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm js-open-task-modal" data-category-id="">
            + Tambah Task
        </button>

        {{-- Popover Kolom Saya --}}
        <div id="colsPopover" class="hidden absolute right-0 top-full mt-2 w-72 bg-white border border-gray-200 rounded shadow-lg z-30">
            <form method="POST" action="{{ route('tasks.visible_categories.save') }}" class="p-3 text-sm">
                @csrf
                <div class="text-xs text-gray-500 mb-2">Pilih kategori yang ingin ditampilkan di board:</div>

                <div class="max-h-72 overflow-y-auto space-y-1.5 pr-1">
                    <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 px-1 py-0.5 rounded">
                        <input type="checkbox" name="show_uncategorized" value="1" @checked($showUncategorized)>
                        <span class="inline-block w-2.5 h-2.5 rounded-full" style="background:#94a3b8"></span>
                        <span>Tanpa Kategori</span>
                    </label>
                    @foreach($allCategories as $c)
                        <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 px-1 py-0.5 rounded">
                            <input type="checkbox" name="visible_category_ids[]" value="{{ $c->id }}"
                                   @checked(!in_array($c->id, $hiddenIds, true))>
                            <span class="inline-block w-2.5 h-2.5 rounded-full" style="background:{{ $c->color }}"></span>
                            <span>{{ $c->name }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="flex items-center justify-between mt-3 pt-2 border-t border-gray-100">
                    <div class="flex gap-2">
                        <button type="button" id="colsCheckAll" class="text-xs text-blue-600 hover:underline">Pilih semua</button>
                        <button type="button" id="colsUncheckAll" class="text-xs text-gray-500 hover:underline">Kosongkan</button>
                    </div>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-xs font-semibold">
                        💾 Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="mb-3 bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm">{{ session('success') }}</div>
@endif

@php
    // Helper: pilih warna teks (putih/hitam) berdasarkan luminance background.
    $textOnBg = function ($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) < 6) return '#0f172a';
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        return (0.299*$r + 0.587*$g + 0.114*$b) > 0.62 ? '#0f172a' : '#ffffff';
    };
@endphp

<div class="task-board" id="taskBoard">
    @php
        $uncategorized = $tasksByCategory[0] ?? collect();
    @endphp

    @if($showUncategorized)
    {{-- Kolom: Tanpa Kategori --}}
    @php $uncatColor = '#94a3b8'; $uncatText = $textOnBg($uncatColor); @endphp
    <div class="task-column" data-category-id="" data-sort-locked="1">
        <div class="task-column-header" style="background: {{ $uncatColor }}; color: {{ $uncatText }};">
            <div class="task-column-title">Tanpa Kategori</div>
            <div class="task-column-count" style="color: {{ $uncatText }};">{{ $uncategorized->count() }}</div>
        </div>
        <div class="task-column-body" data-category-id="">
            @foreach($uncategorized as $task)
                @include('erp.tasks._card', ['task' => $task])
            @endforeach
            <button type="button" class="task-add-btn js-open-task-modal" data-category-id="">+ Tambah</button>
        </div>
        <div class="task-col-resize" title="Geser untuk ubah lebar kolom"></div>
    </div>
    @endif

    @php
        $authUser = auth()->user();
        $canEditAny = $authUser && ($authUser->isAdmin() || $authUser->isSuperAdmin());
    @endphp
    @foreach($categories as $cat)
        @php
            $colTasks = $tasksByCategory[$cat->id] ?? collect();
            $bg = $cat->color ?: '#94a3b8';
            $txt = $textOnBg($bg);
            $canEditCat = $canEditAny || ($authUser && (int) $cat->created_by_user_id === (int) $authUser->id);
        @endphp
        <div class="task-column" data-category-id="{{ $cat->id }}">
            <div class="task-column-header" draggable="true" style="background: {{ $bg }}; color: {{ $txt }};">
                <div class="task-column-title">{{ $cat->name }}</div>
                <div class="flex items-center gap-1">
                    @if($canEditCat)
                        <button type="button"
                                class="task-column-edit js-edit-category"
                                title="Edit kategori"
                                data-category-id="{{ $cat->id }}"
                                data-name="{{ $cat->name }}"
                                data-color="{{ $cat->color ?: '#94a3b8' }}"
                                data-active="{{ $cat->is_active ? 1 : 0 }}"
                                style="color: {{ $txt }};">✎</button>
                    @endif
                    <div class="task-column-count" style="color: {{ $txt }};">{{ $colTasks->count() }}</div>
                </div>
            </div>
            <div class="task-column-body" data-category-id="{{ $cat->id }}">
                @foreach($colTasks as $task)
                    @include('erp.tasks._card', ['task' => $task])
                @endforeach
                <button type="button" class="task-add-btn js-open-task-modal" data-category-id="{{ $cat->id }}">+ Tambah</button>
            </div>
            <div class="task-col-resize" title="Geser untuk ubah lebar kolom"></div>
        </div>
    @endforeach

    <button type="button" id="btnOpenCategoryModal" class="task-add-column">
        + Kategori Baru
    </button>

    @if(!$showUncategorized && $categories->isEmpty())
        <div class="text-sm text-gray-500 px-3 py-6">
            Semua kolom disembunyikan. Klik <strong>👁 Kolom Saya</strong> untuk menampilkan kembali.
        </div>
    @endif
</div>

{{-- =================== MODAL: Tambah Task =================== --}}
<div id="taskModal" class="tm-modal tm-hidden" role="dialog" aria-modal="true">
    <div class="tm-modal-card">
        <div class="tm-modal-head">
            <div class="text-base font-semibold">Tambah Task</div>
            <button type="button" class="tm-modal-close js-close-modal">✕</button>
        </div>
        <form id="taskModalForm" method="POST" action="{{ route('tasks.store') }}">
            @csrf
            <div class="tm-modal-body space-y-3">
                <div id="taskModalErrors" class="hidden bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm"></div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Judul *</label>
                    <input type="text" name="title" required autofocus
                           class="w-full border rounded px-3 py-2 text-sm">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Kategori</label>
                        <select name="category_id" id="taskModalCategorySelect" class="w-full border rounded px-2 py-2 text-sm">
                            <option value="">— Tanpa Kategori —</option>
                            @foreach($allCategories as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Ditugaskan ke</label>
                        <select name="assignee_user_id" class="w-full border rounded px-2 py-2 text-sm">
                            <option value="">— Tidak ditentukan —</option>
                            @foreach($assignableUsers as $u)
                                <option value="{{ $u->id }}" @selected($u->id === auth()->id())>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
                        <select name="priority" class="w-full border rounded px-2 py-2 text-sm">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Status</label>
                        <select name="status" class="w-full border rounded px-2 py-2 text-sm">
                            <option value="open" selected>Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="done">Selesai</option>
                            <option value="cancelled">Dibatalkan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Jatuh tempo</label>
                        <input type="date" name="due_date" class="w-full border rounded px-2 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Deskripsi</label>
                    <textarea name="description" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                </div>

                <div class="text-[11px] text-gray-400">
                    Subtask & link dokumen bisa ditambahkan setelah task dibuat (klik task → Edit).
                </div>
            </div>
            <div class="tm-modal-foot">
                <button type="button" class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm js-close-modal">Batal</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-semibold">
                    💾 Simpan
                </button>
            </div>
        </form>
    </div>
</div>

{{-- =================== MODAL: Detail Task =================== --}}
<div id="taskDetailModal" class="tm-modal tm-hidden" role="dialog" aria-modal="true">
    <div class="tm-modal-card" style="max-width:720px;">
        <div class="tm-modal-head">
            <div class="text-base font-semibold" id="detailTitle">Detail Task</div>
            <button type="button" class="tm-modal-close js-close-modal">✕</button>
        </div>
        <div class="tm-modal-body space-y-4">
            <div class="text-xs text-gray-500" id="detailMeta">—</div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-xs font-semibold text-gray-600">Deskripsi</label>
                    <button type="button" id="btnSaveDescription" class="text-xs text-blue-600 hover:underline hidden">💾 Simpan</button>
                </div>
                <textarea id="detailDescription" rows="4"
                          class="w-full border rounded px-3 py-2 text-sm"
                          placeholder="Tambahkan deskripsi..."></textarea>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-semibold text-gray-600">Dokumen Terkait</label>
                </div>
                <div id="detailLinksList" class="space-y-1.5 mb-2"></div>

                {{-- Form add link: input nomor dokumen saja, tipe auto-detect dari config. --}}
                <div class="flex gap-2 items-start">
                    <input type="text" id="newLinkNumber"
                           placeholder="Nomor dokumen (mis. SQ/2026/05/00001, SO/..., SKU produk)"
                           class="border rounded px-2 py-1.5 text-xs flex-1">
                    <button type="button" id="btnAddLink"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-xs whitespace-nowrap">+ Link</button>
                </div>
                <div id="linkError" class="text-xs text-red-600 mt-1 hidden"></div>
            </div>

            <div>
                <label class="text-xs font-semibold text-gray-600 block mb-2">Riwayat</label>
                <div id="detailHistoryList" class="space-y-1 text-xs text-gray-600 max-h-60 overflow-y-auto border rounded p-2 bg-gray-50">
                    <div class="text-gray-400 italic">Memuat…</div>
                </div>
            </div>
        </div>
        <div class="tm-modal-foot">
            <button type="button" class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm js-close-modal">Tutup</button>
        </div>
    </div>
</div>

{{-- =================== MODAL: Tambah/Edit Kategori =================== --}}
<div id="categoryModal" class="tm-modal tm-hidden" role="dialog" aria-modal="true">
    <div class="tm-modal-card small">
        <div class="tm-modal-head">
            <div id="categoryModalTitle" class="text-base font-semibold">Tambah Kategori</div>
            <button type="button" class="tm-modal-close js-close-modal">✕</button>
        </div>
        <form id="categoryModalForm" method="POST" action="{{ route('tasks.categories.store') }}"
              data-store-url="{{ route('tasks.categories.store') }}">
            @csrf
            <input type="hidden" name="_method" id="categoryModalMethod" value="POST">
            <div class="tm-modal-body space-y-3">
                <div id="categoryModalErrors" class="hidden bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm"></div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Nama *</label>
                    <input type="text" name="name" id="categoryModalName" required autofocus
                           class="w-full border rounded px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Warna</label>
                    <div class="flex gap-2 items-center">
                        <input type="color" name="color" id="categoryModalColor" value="#94a3b8" class="w-12 h-9 border rounded cursor-pointer">
                        <span class="text-xs text-gray-500">Warna badge kategori di Board</span>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" id="categoryModalActive" value="1" checked>
                    Aktif
                </label>
            </div>
            <div class="tm-modal-foot">
                <button type="button" class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm js-close-modal">Batal</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-semibold">
                    💾 Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const board = document.getElementById('taskBoard');
    if (!board) return;

    // ============ Column resize + width persistence ============
    // Lebar kolom disimpan per (user-browser) di localStorage, key per kategori.
    // Saat kolom dilebarin cukup luas, CSS grid auto-fill di .task-column-body otomatis
    // bikin sub-kolom — task yang bawah naik ke atas mengisi ruang.
    const WIDTH_STORAGE_KEY = 'tm-col-widths';
    const MIN_COL_WIDTH = 280;
    const MAX_COL_WIDTH = 1400;

    function loadWidths() {
        try { return JSON.parse(localStorage.getItem(WIDTH_STORAGE_KEY) || '{}'); }
        catch (_) { return {}; }
    }
    function saveWidth(catKey, w) {
        try {
            const widths = loadWidths();
            widths[catKey] = w;
            localStorage.setItem(WIDTH_STORAGE_KEY, JSON.stringify(widths));
        } catch (_) {}
    }
    function catKeyOf(col) {
        return col.dataset.categoryId || '__uncat__';
    }

    // Apply saved widths on load
    (function restoreWidths() {
        const widths = loadWidths();
        document.querySelectorAll('.task-column').forEach(col => {
            const w = widths[catKeyOf(col)];
            if (w && w >= MIN_COL_WIDTH && w <= MAX_COL_WIDTH) {
                col.style.width = w + 'px';
            }
        });
    })();

    // Setup resize handle per kolom
    document.querySelectorAll('.task-col-resize').forEach(handle => {
        handle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const col = handle.closest('.task-column');
            if (!col) return;
            const startX = e.clientX;
            const startW = col.offsetWidth;
            handle.classList.add('is-resizing');
            document.body.classList.add('task-col-resizing');

            const onMove = (ev) => {
                const delta = ev.clientX - startX;
                const newW = Math.max(MIN_COL_WIDTH, Math.min(MAX_COL_WIDTH, startW + delta));
                col.style.width = newW + 'px';
            };
            const onUp = () => {
                handle.classList.remove('is-resizing');
                document.body.classList.remove('task-col-resizing');
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                saveWidth(catKeyOf(col), parseInt(col.style.width, 10));
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
        // Double-click reset to default
        handle.addEventListener('dblclick', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const col = handle.closest('.task-column');
            if (!col) return;
            col.style.width = '';
            saveWidth(catKeyOf(col), null);
            const widths = loadWidths();
            delete widths[catKeyOf(col)];
            try { localStorage.setItem(WIDTH_STORAGE_KEY, JSON.stringify(widths)); } catch (_) {}
        });
    });

    let draggedEl = null;
    let draggedColumn = null;

    board.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.task-card');
        if (!card) return;
        draggedEl = card;
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    board.addEventListener('dragend', (e) => {
        const card = e.target.closest('.task-card');
        if (card) card.classList.remove('dragging');
        document.querySelectorAll('.task-column-body.drag-over').forEach(el => el.classList.remove('drag-over'));
        draggedEl = null;
    });

    document.querySelectorAll('.task-column-body').forEach(body => {
        body.addEventListener('dragover', (e) => {
            if (draggedColumn) return; // sedang drag kolom, abaikan
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            body.classList.add('drag-over');
            const after = getDragAfterElement(body, e.clientX, e.clientY);
            if (!draggedEl) return;
            if (after == null) {
                // Sisipkan SEBELUM tombol "+ Tambah" supaya tombol tetap di slot akhir
                const addBtn = body.querySelector('.task-add-btn');
                if (addBtn) {
                    body.insertBefore(draggedEl, addBtn);
                } else {
                    body.appendChild(draggedEl);
                }
            } else {
                body.insertBefore(draggedEl, after);
            }
        });
        body.addEventListener('dragleave', () => body.classList.remove('drag-over'));
        body.addEventListener('drop', (e) => {
            if (draggedColumn) return;
            e.preventDefault();
            body.classList.remove('drag-over');
            if (!draggedEl) return;
            const taskId = draggedEl.dataset.taskId;
            const newCategoryId = body.dataset.categoryId || '';
            // Posisi dihitung dari task-card saja (abaikan tombol "+ Tambah")
            const cards = Array.from(body.querySelectorAll('.task-card'));
            const position = cards.indexOf(draggedEl);
            persistMove(taskId, newCategoryId, position);
        });
    });

    // Cari card "berikutnya dalam reading order" yg paling dekat dgn cursor.
    // Versi sebelumnya hanya pakai Y — di grid multi-column, X juga harus diperhitungkan.
    function getDragAfterElement(container, x, y) {
        const cards = [...container.querySelectorAll('.task-card:not(.dragging)')];
        return cards.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const cx = box.left + box.width / 2;
            const cy = box.top + box.height / 2;
            const dx = x - cx;
            const dy = y - cy;
            // Card "after cursor" = cursor di atas (dy<0) atau sebaris dgn cursor di kirinya.
            const sameRow = Math.abs(dy) < box.height / 2;
            const isAfter = dy < 0 || (sameRow && dx < 0);
            if (!isAfter) return closest;
            const dist = dx * dx + dy * dy;
            if (dist < closest.dist) return { dist, element: child };
            return closest;
        }, { dist: Number.POSITIVE_INFINITY }).element;
    }

    // === Modals (Tambah Task & Tambah Kategori) ===
    const taskModal = document.getElementById('taskModal');
    const taskForm = document.getElementById('taskModalForm');
    const taskErrors = document.getElementById('taskModalErrors');
    const taskCatSelect = document.getElementById('taskModalCategorySelect');

    const catModal = document.getElementById('categoryModal');
    const catForm = document.getElementById('categoryModalForm');
    const catErrors = document.getElementById('categoryModalErrors');

    const detailModal = document.getElementById('taskDetailModal');

    function openModal(modal) {
        if (!modal) return;
        // Tutup semua modal lain dulu — jamin hanya satu modal terbuka
        document.querySelectorAll('.tm-modal').forEach(m => {
            if (m !== modal) m.classList.add('tm-hidden');
        });
        modal.classList.remove('tm-hidden');
        const firstInput = modal.querySelector('input[autofocus], input:not([type=hidden]):not([type=color])');
        if (firstInput) setTimeout(() => firstInput.focus(), 30);
    }
    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('tm-hidden');
        const errBox = modal.querySelector('[id$="Errors"]');
        if (errBox) { errBox.classList.add('hidden'); errBox.innerHTML = ''; }
        const form = modal.querySelector('form');
        if (form) form.reset();
    }

    document.querySelectorAll('.js-open-task-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            const cid = btn.dataset.categoryId || '';
            if (taskCatSelect) taskCatSelect.value = cid;
            openModal(taskModal);
        });
    });
    const catTitle = document.getElementById('categoryModalTitle');
    const catMethod = document.getElementById('categoryModalMethod');
    const catName = document.getElementById('categoryModalName');
    const catColor = document.getElementById('categoryModalColor');
    const catActive = document.getElementById('categoryModalActive');
    const catStoreUrl = catForm ? catForm.dataset.storeUrl : '';

    function resetCategoryModal() {
        if (!catForm) return;
        catForm.action = catStoreUrl;
        if (catMethod) catMethod.value = 'POST';
        if (catTitle) catTitle.textContent = 'Tambah Kategori';
        if (catName) catName.value = '';
        if (catColor) catColor.value = '#94a3b8';
        if (catActive) catActive.checked = true;
        if (catErrors) { catErrors.classList.add('hidden'); catErrors.innerHTML = ''; }
    }

    const btnCat = document.getElementById('btnOpenCategoryModal');
    if (btnCat) btnCat.addEventListener('click', () => {
        resetCategoryModal();
        openModal(catModal);
    });

    document.querySelectorAll('.js-edit-category').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            resetCategoryModal();
            const id = btn.dataset.categoryId;
            if (!catForm || !id) return;
            catForm.action = '/erp/tasks/categories/' + id;
            if (catMethod) catMethod.value = 'PATCH';
            if (catTitle) catTitle.textContent = 'Edit Kategori';
            if (catName) catName.value = btn.dataset.name || '';
            if (catColor) catColor.value = btn.dataset.color || '#94a3b8';
            if (catActive) catActive.checked = btn.dataset.active === '1';
            openModal(catModal);
        });
    });

    document.querySelectorAll('.js-close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            closeModal(btn.closest('.tm-modal'));
        });
    });
    [taskModal, catModal, detailModal].forEach(m => {
        if (!m) return;
        m.addEventListener('click', (e) => {
            if (e.target === m) closeModal(m);
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            [taskModal, catModal, detailModal].forEach(m => {
                if (m && !m.classList.contains('tm-hidden')) closeModal(m);
            });
        }
    });

    function showErrors(box, errors, fallbackMsg) {
        if (!box) return;
        const items = [];
        if (errors && typeof errors === 'object') {
            for (const k of Object.keys(errors)) {
                (errors[k] || []).forEach(msg => items.push(msg));
            }
        }
        box.innerHTML = items.length
            ? '<ul class="list-disc pl-5">' + items.map(t => '<li>' + t + '</li>').join('') + '</ul>'
            : (fallbackMsg || 'Gagal menyimpan.');
        box.classList.remove('hidden');
    }

    async function submitModalForm(form, errBox) {
        const submitBtn = form.querySelector('button[type=submit]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('opacity-60'); }
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            if (res.ok) {
                location.reload();
                return;
            }
            if (res.status === 422) {
                const json = await res.json();
                showErrors(errBox, json.errors, json.message);
            } else {
                showErrors(errBox, null, 'Gagal menyimpan (HTTP ' + res.status + ').');
            }
        } catch (err) {
            showErrors(errBox, null, 'Gagal menyimpan: koneksi bermasalah.');
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('opacity-60'); }
        }
    }

    if (taskForm) {
        taskForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitModalForm(taskForm, taskErrors);
        });
    }
    if (catForm) {
        catForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitModalForm(catForm, catErrors);
        });
    }

    // === Popover "Kolom Saya" ===
    const btnPop = document.getElementById('btnToggleColsPopover');
    const pop = document.getElementById('colsPopover');
    if (btnPop && pop) {
        btnPop.addEventListener('click', (e) => {
            e.stopPropagation();
            pop.classList.toggle('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!pop.classList.contains('hidden') && !pop.contains(e.target) && e.target !== btnPop) {
                pop.classList.add('hidden');
            }
        });
        const allBoxes = () => pop.querySelectorAll('input[type=checkbox]');
        const btnAll = document.getElementById('colsCheckAll');
        const btnNone = document.getElementById('colsUncheckAll');
        if (btnAll) btnAll.addEventListener('click', () => allBoxes().forEach(cb => cb.checked = true));
        if (btnNone) btnNone.addEventListener('click', () => allBoxes().forEach(cb => cb.checked = false));
    }

    function persistMove(taskId, categoryId, position) {
        const body = new FormData();
        body.append('_method', 'PATCH');
        body.append('_token', csrf);
        if (categoryId) body.append('category_id', categoryId);
        body.append('position', position);

        fetch('/erp/tasks/' + taskId + '/move', {
            method: 'POST',
            body,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => {
            if (!r.ok) {
                alert('Gagal pindah task. Reload halaman.');
                location.reload();
            }
        }).catch(() => {
            alert('Gagal pindah task. Reload halaman.');
            location.reload();
        });
    }

    // ============ Column drag-drop (header sebagai handle) ============
    // Desain: DOM TIDAK dimutasi saat dragover (rentan glitch & browser-specific).
    // dragover hanya menyimpan target insert + tampilkan indikator.
    // drop yang melakukan DOM move + persist.
    let dropTargetCol = null;
    let dropInsertBefore = false;

    board.addEventListener('dragstart', (e) => {
        const header = e.target.closest('.task-column-header');
        if (!header || !header.getAttribute('draggable')) return;
        const col = header.closest('.task-column');
        if (!col || col.dataset.sortLocked === '1') return;
        draggedColumn = col;
        col.classList.add('col-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'col:' + (col.dataset.categoryId || ''));
    });

    board.addEventListener('dragend', () => {
        if (draggedColumn) draggedColumn.classList.remove('col-dragging');
        document.querySelectorAll('.task-column.col-drop-before, .task-column.col-drop-after')
            .forEach(c => c.classList.remove('col-drop-before', 'col-drop-after'));
        draggedColumn = null;
        dropTargetCol = null;
    });

    board.addEventListener('dragover', (e) => {
        if (!draggedColumn) return;
        const overCol = e.target.closest('.task-column');
        if (!overCol || overCol === draggedColumn) return;
        if (overCol.dataset.sortLocked === '1') return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        const rect = overCol.getBoundingClientRect();
        const insertBefore = e.clientX < (rect.left + rect.width / 2);

        if (overCol === dropTargetCol && insertBefore === dropInsertBefore) return;
        document.querySelectorAll('.task-column.col-drop-before, .task-column.col-drop-after')
            .forEach(c => c.classList.remove('col-drop-before', 'col-drop-after'));
        overCol.classList.add(insertBefore ? 'col-drop-before' : 'col-drop-after');
        dropTargetCol = overCol;
        dropInsertBefore = insertBefore;
    });

    board.addEventListener('drop', (e) => {
        if (!draggedColumn) return;
        e.preventDefault();
        document.querySelectorAll('.task-column.col-drop-before, .task-column.col-drop-after')
            .forEach(c => c.classList.remove('col-drop-before', 'col-drop-after'));

        if (dropTargetCol && dropTargetCol !== draggedColumn) {
            if (dropInsertBefore) {
                dropTargetCol.parentNode.insertBefore(draggedColumn, dropTargetCol);
            } else {
                dropTargetCol.parentNode.insertBefore(draggedColumn, dropTargetCol.nextSibling);
            }
            const ids = [...board.querySelectorAll('.task-column')]
                .filter(c => c.dataset.categoryId && c.dataset.sortLocked !== '1')
                .map(c => c.dataset.categoryId);
            persistCategoryOrder(ids);
        }
        dropTargetCol = null;
    });

    function persistCategoryOrder(ids) {
        const fd = new FormData();
        fd.append('_token', csrf);
        fd.append('_method', 'PATCH');
        ids.forEach(id => fd.append('ids[]', id));
        fetch('/erp/tasks/categories/reorder', {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(() => {
            alert('Gagal simpan urutan kategori. Reload halaman.');
            location.reload();
        });
    }

    // ============ Card interactions (status, subtask, assignee, delete, detail) ============

    async function api(method, url, body = null) {
        const opts = {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
        };
        const fd = new FormData();
        fd.append('_token', csrf);
        if (method !== 'POST') fd.append('_method', method);
        if (body) for (const [k, v] of Object.entries(body)) fd.append(k, v);
        opts.body = fd;
        return fetch(url, opts);
    }

    // Stop drag/click bubble from interactive elements inside card
    document.addEventListener('mousedown', (e) => {
        if (e.target.closest('button, input, select, textarea, .task-sub-row')) {
            const card = e.target.closest('.task-card');
            if (card) card.draggable = false;
        }
    });
    document.addEventListener('mouseup', () => {
        document.querySelectorAll('.task-card').forEach(c => c.draggable = true);
    });

    // === Toggle status (Google Tasks-style checkbox) ===
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.task-check-gt');
        if (!btn) return;
        e.stopPropagation();
        const taskId = btn.dataset.taskId;
        const wasDone = btn.classList.contains('checked');
        const newStatus = wasDone ? 'open' : 'done';

        // Optimistic toggle
        btn.classList.toggle('checked');
        btn.innerHTML = wasDone ? '' : '<span class="task-check-tick">✓</span>';
        const card = btn.closest('.task-card');
        if (card) card.classList.toggle('is-done', !wasDone);

        const res = await api('PATCH', '/erp/tasks/' + taskId + '/status', { status: newStatus });
        if (!res.ok) {
            // Rollback
            btn.classList.toggle('checked');
            btn.innerHTML = wasDone ? '<span class="task-check-tick">✓</span>' : '';
            if (card) card.classList.toggle('is-done', wasDone);
            alert('Gagal update status.');
        }
    });

    // === Delete task ===
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-delete-task');
        if (!btn) return;
        e.stopPropagation();
        const taskId = btn.dataset.taskId;
        const card = btn.closest('.task-card');
        const title = card?.querySelector('.task-card-title')?.textContent?.trim() || 'task ini';
        if (!confirm('Hapus task "' + title + '"?')) return;

        const res = await api('DELETE', '/erp/tasks/' + taskId);
        if (res.ok) {
            card?.remove();
        } else {
            alert('Gagal hapus task.');
        }
    });

    // === Hide task (creator-only, sembunyikan dari board sendiri) ===
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-hide-task');
        if (!btn) return;
        e.stopPropagation();
        const taskId = btn.dataset.taskId;
        const card = btn.closest('.task-card');
        const res = await api('POST', '/erp/tasks/' + taskId + '/hide');
        if (res.ok) {
            card?.remove();
        } else {
            alert('Gagal sembunyikan task.');
        }
    });

    // === Subtask toggle ===
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.task-sub-check');
        if (!btn) return;
        e.stopPropagation();
        const subId = btn.dataset.subId;
        const wasDone = btn.classList.contains('checked');

        btn.classList.toggle('checked');
        btn.innerHTML = wasDone ? '' : '<span class="task-check-tick-sm">✓</span>';
        const titleEl = btn.parentElement.querySelector('.task-sub-title');
        if (titleEl) titleEl.classList.toggle('is-done', !wasDone);

        const res = await api('PATCH', '/erp/tasks/subtasks/' + subId + '/toggle');
        if (!res.ok) {
            btn.classList.toggle('checked');
            btn.innerHTML = wasDone ? '<span class="task-check-tick-sm">✓</span>' : '';
            if (titleEl) titleEl.classList.toggle('is-done', wasDone);
            alert('Gagal toggle subtask.');
        }
    });

    // === Subtask delete ===
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.task-sub-del');
        if (!btn) return;
        e.stopPropagation();
        const row = btn.closest('.task-sub-row');
        const subId = btn.dataset.subId;
        const res = await api('DELETE', '/erp/tasks/subtasks/' + subId);
        if (res.ok) row?.remove();
        else alert('Gagal hapus subtask.');
    });

    // === Show subtask input ===
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-show-sub-input');
        if (!btn) return;
        e.stopPropagation();
        const taskId = btn.dataset.taskId;
        const wrap = btn.parentElement.querySelector('.task-add-sub-input[data-task-id="' + taskId + '"]');
        if (!wrap) return;
        btn.classList.add('hidden');
        wrap.classList.remove('hidden');
        wrap.querySelector('input')?.focus();
    });

    // === Add subtask on Enter ===
    document.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.js-new-sub-input');
        if (!input) return;
        e.preventDefault();
        const wrap = input.closest('.task-add-sub-input');
        const taskId = wrap.dataset.taskId;
        const title = input.value.trim();
        if (!title) return;

        const res = await api('POST', '/erp/tasks/' + taskId + '/subtasks', { title });
        if (!res.ok) { alert('Gagal tambah subtask.'); return; }
        const json = await res.json();
        const sub = json.subtask;

        // Append new row
        const subtasksDiv = document.querySelector('.task-subtasks[data-task-id="' + taskId + '"]');
        if (subtasksDiv) {
            const row = document.createElement('div');
            row.className = 'task-sub-row';
            row.dataset.subId = sub.id;
            row.innerHTML =
                '<button type="button" class="task-sub-check" data-sub-id="' + sub.id + '" title="Tandai selesai"></button>' +
                '<span class="task-sub-title">' + escapeHtml(sub.title) + '</span>' +
                '<button type="button" class="task-sub-del" data-sub-id="' + sub.id + '" title="Hapus subtask">×</button>';
            subtasksDiv.appendChild(row);
        }
        input.value = '';
    });

    // === Hide subtask input on blur if empty ===
    document.addEventListener('blur', (e) => {
        const input = e.target.closest('.js-new-sub-input');
        if (!input || input.value.trim() !== '') return;
        const wrap = input.closest('.task-add-sub-input');
        const taskId = wrap.dataset.taskId;
        wrap.classList.add('hidden');
        const showBtn = wrap.parentElement.querySelector('.js-show-sub-input[data-task-id="' + taskId + '"]');
        if (showBtn) showBtn.classList.remove('hidden');
    }, true);

    // === Assignee change ===
    document.addEventListener('change', async (e) => {
        const sel = e.target.closest('.task-assignee-select');
        if (!sel) return;
        const taskId = sel.dataset.taskId;
        const userId = sel.value || '';

        const res = await api('PATCH', '/erp/tasks/' + taskId + '/assignee', { assignee_user_id: userId });
        if (!res.ok) {
            sel.value = sel.dataset.current || '';
            alert('Gagal ubah penugasan.');
        } else {
            sel.dataset.current = userId;
        }
    });

    // === Priority change (auto re-sort dalam kolom) ===
    document.addEventListener('change', async (e) => {
        const sel = e.target.closest('.task-priority-select');
        if (!sel) return;
        const taskId = sel.dataset.taskId;
        const newPrio = sel.value;
        const oldPrio = sel.dataset.current || 'normal';

        const res = await api('PATCH', '/erp/tasks/' + taskId + '/priority', { priority: newPrio });
        if (!res.ok) {
            sel.value = oldPrio;
            alert('Gagal ubah prioritas.');
            return;
        }
        sel.dataset.current = newPrio;
        sel.classList.remove('prio-high', 'prio-normal', 'prio-low');
        sel.classList.add('prio-' + newPrio);

        // Re-sort: high di atas, lalu normal, lalu low
        const card = sel.closest('.task-card');
        const body = card?.closest('.task-column-body');
        if (!body) return;
        const rank = p => p === 'high' ? 3 : (p === 'normal' ? 2 : 1);
        const newRank = rank(newPrio);
        const siblings = [...body.querySelectorAll('.task-card')].filter(c => c !== card);
        let inserted = false;
        for (const s of siblings) {
            const sPrio = s.querySelector('.task-priority-select')?.dataset.current || 'normal';
            if (rank(sPrio) < newRank) {
                body.insertBefore(card, s);
                inserted = true;
                break;
            }
        }
        if (!inserted) body.appendChild(card);

        // Persist position juga
        const newPos = [...body.children].filter(c => c.classList.contains('task-card')).indexOf(card);
        persistMove(taskId, body.dataset.categoryId || '', newPos);
    });

    // Stop title click from bubbling drag/etc.
    document.addEventListener('click', (e) => {
        const titleEl = e.target.closest('.js-open-detail');
        if (!titleEl) return;
        e.stopPropagation();
        openTaskDetail(titleEl.dataset.taskId);
    });

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s ?? '';
        return div.innerHTML;
    }

    // ============ Task Detail Modal ============
    let currentDetailTaskId = null;
    let originalDescription = '';

    async function openTaskDetail(taskId) {
        currentDetailTaskId = taskId;
        openModal(detailModal);
        document.getElementById('detailTitle').textContent = 'Memuat…';
        document.getElementById('detailMeta').textContent = '';
        document.getElementById('detailDescription').value = '';
        document.getElementById('detailLinksList').innerHTML = '';
        document.getElementById('detailHistoryList').innerHTML = '<div class="text-gray-400 italic">Memuat…</div>';
        document.getElementById('linkError').classList.add('hidden');
        document.getElementById('btnSaveDescription').classList.add('hidden');

        const res = await fetch('/erp/tasks/' + taskId + '/json', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) {
            document.getElementById('detailTitle').textContent = 'Gagal memuat task';
            return;
        }
        const data = await res.json();
        renderDetail(data);
    }

    function renderDetail(data) {
        const t = data.task;
        document.getElementById('detailTitle').textContent = t.title;

        const metaParts = [];
        if (t.category) metaParts.push('🗂 ' + t.category.name);
        if (t.assignee) metaParts.push('→ ' + t.assignee.name);
        if (t.due_date) metaParts.push('📅 ' + t.due_date);
        metaParts.push('Status: ' + t.status);
        metaParts.push('Prioritas: ' + t.priority);
        if (t.creator) metaParts.push('Dibuat oleh: ' + t.creator);
        if (t.created_at) metaParts.push('• ' + t.created_at);
        document.getElementById('detailMeta').textContent = metaParts.join(' · ');

        originalDescription = t.description || '';
        document.getElementById('detailDescription').value = originalDescription;

        const linksHtml = data.links.length
            ? data.links.map(l => {
                const labelEl = l.url
                    ? '<a href="' + l.url + '" target="_blank" class="text-blue-600 hover:underline">' + escapeHtml(l.label) + '</a>'
                    : '<span class="text-gray-400 line-through">' + escapeHtml(l.label) + ' (dokumen tidak ada)</span>';
                // Auto-link (dari taskable polymorphic) tidak bisa di-hapus user;
                // tampilkan icon kunci kecil sebagai indikator.
                const trailing = l.auto
                    ? '<span class="text-gray-300" title="Otomatis dari sumber dokumen">🔗</span>'
                    : '<button type="button" class="text-red-500 hover:text-red-700 js-del-link" data-link-id="' + l.id + '">×</button>';
                return '<div class="flex items-center justify-between bg-gray-50 px-2 py-1.5 rounded text-xs">' +
                    '<span><span class="text-gray-500">' + escapeHtml(l.type) + ':</span> ' + labelEl + '</span>' +
                    trailing +
                    '</div>';
            }).join('')
            : '<div class="text-xs text-gray-400 italic">Belum ada dokumen.</div>';
        document.getElementById('detailLinksList').innerHTML = linksHtml;

        const histHtml = data.histories.length
            ? data.histories.map(h => {
                return '<div class="flex gap-2"><span class="text-gray-400 whitespace-nowrap">' + escapeHtml(h.at) + '</span>' +
                    '<span class="flex-1">' + escapeHtml(h.summary) +
                    (h.user ? ' <span class="text-gray-400">— ' + escapeHtml(h.user) + '</span>' : '') +
                    '</span></div>';
            }).join('')
            : '<div class="text-gray-400 italic">Belum ada riwayat.</div>';
        document.getElementById('detailHistoryList').innerHTML = histHtml;
    }

    // Description: show save button when modified
    const descTextarea = document.getElementById('detailDescription');
    const btnSaveDesc = document.getElementById('btnSaveDescription');
    if (descTextarea) {
        descTextarea.addEventListener('input', () => {
            if (descTextarea.value !== originalDescription) {
                btnSaveDesc.classList.remove('hidden');
            } else {
                btnSaveDesc.classList.add('hidden');
            }
        });
    }
    if (btnSaveDesc) {
        btnSaveDesc.addEventListener('click', async () => {
            if (!currentDetailTaskId) return;
            const res = await api('PATCH', '/erp/tasks/' + currentDetailTaskId + '/description', {
                description: descTextarea.value,
            });
            if (res.ok) {
                originalDescription = descTextarea.value;
                btnSaveDesc.classList.add('hidden');
                btnSaveDesc.textContent = '✓ Tersimpan';
                setTimeout(() => { btnSaveDesc.textContent = '💾 Simpan'; }, 1500);
            } else {
                alert('Gagal simpan deskripsi.');
            }
        });
    }

    // Add link — input nomor dokumen, backend auto-detect tipe-nya
    const btnAddLink = document.getElementById('btnAddLink');
    const newLinkNumberInput = document.getElementById('newLinkNumber');

    async function submitAddLink() {
        if (!currentDetailTaskId) return;
        const number = newLinkNumberInput.value.trim();
        const errEl = document.getElementById('linkError');
        errEl.classList.add('hidden');

        if (!number) {
            errEl.textContent = 'Isi nomor dokumen terlebih dahulu.';
            errEl.classList.remove('hidden');
            return;
        }
        const res = await api('POST', '/erp/tasks/' + currentDetailTaskId + '/links', {
            linked_number: number,
        });
        if (res.ok) {
            newLinkNumberInput.value = '';
            // Reload detail
            openTaskDetail(currentDetailTaskId);
        } else if (res.status === 422) {
            const json = await res.json();
            const errs = json.errors || {};
            const first = Object.values(errs)[0]?.[0] || json.message || 'Gagal tambah link.';
            errEl.textContent = first;
            errEl.classList.remove('hidden');
        } else {
            errEl.textContent = 'Gagal tambah link.';
            errEl.classList.remove('hidden');
        }
    }

    btnAddLink?.addEventListener('click', submitAddLink);
    // Enter di input langsung submit
    newLinkNumberInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            submitAddLink();
        }
    });

    // Delete link (delegated)
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-del-link');
        if (!btn) return;
        if (!confirm('Hapus link ini?')) return;
        const linkId = btn.dataset.linkId;
        const res = await api('DELETE', '/erp/tasks/links/' + linkId);
        if (res.ok && currentDetailTaskId) {
            openTaskDetail(currentDetailTaskId);
        } else if (!res.ok) {
            alert('Gagal hapus link.');
        }
    });
})();
</script>
@endsection
