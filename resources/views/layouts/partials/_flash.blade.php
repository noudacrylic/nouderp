{{--
    Notifikasi global (toast) — safety net agar SETIAP halaman menampilkan alasan
    saat submit gagal / berhasil. Controller cukup pakai:
      ->with('error', '...')   ->with('success', '...')   ->with('warning', '...')
    dan validasi (withErrors / $errors) otomatis muncul di sini.

    Ditempel sebagai overlay fixed (bukan di alur konten) supaya tidak menggeser layout
    dan tetap muncul walau halaman tidak punya blok error sendiri.
--}}
@php
    $flashError   = session('error');
    $flashSuccess = session('success');
    $flashWarning = session('warning');
    $flashStatus  = session('status');
    $hasFlash = $flashError || $flashSuccess || $flashWarning || $flashStatus || ($errors->any());
@endphp

@if($hasFlash)
<div x-data="{ toasts: @js(array_values(array_filter([
        $flashError   ? ['type' => 'error',   'msg' => $flashError]   : null,
        $flashWarning ? ['type' => 'warning', 'msg' => $flashWarning] : null,
        $flashStatus  ? ['type' => 'success', 'msg' => $flashStatus]  : null,
        $flashSuccess ? ['type' => 'success', 'msg' => $flashSuccess] : null,
        $errors->any() ? ['type' => 'error', 'msg' => 'Gagal menyimpan — periksa isian berikut:', 'list' => $errors->all()] : null,
    ]))) }"
     class="flash-toasts" x-cloak>
    <template x-for="(t, i) in toasts" :key="i">
        <div class="flash-toast"
             :class="t.type === 'success' ? 'flash-toast--success' : (t.type === 'warning' ? 'flash-toast--warning' : 'flash-toast--error')"
             x-init="if (t.type === 'success') setTimeout(() => toasts.splice(toasts.indexOf(t), 1), 4500)">
            <div class="flash-toast__body">
                <span x-text="t.msg"></span>
                <template x-if="t.list">
                    <ul class="flash-toast__list">
                        <template x-for="(e, j) in t.list" :key="j"><li x-text="e"></li></template>
                    </ul>
                </template>
            </div>
            <button type="button" class="flash-toast__close" @click="toasts.splice(toasts.indexOf(t), 1)" aria-label="Tutup">&times;</button>
        </div>
    </template>
</div>

<style>
    .flash-toasts {
        position: fixed; top: 1rem; right: 1rem; z-index: 2000;
        display: flex; flex-direction: column; gap: 0.5rem;
        max-width: min(420px, calc(100vw - 2rem));
    }
    .flash-toast {
        display: flex; align-items: flex-start; gap: 0.5rem;
        padding: 0.7rem 0.85rem; border-radius: 0.65rem;
        border: 1px solid; font-size: 0.82rem; line-height: 1.35;
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        animation: flash-in 0.18s ease-out;
    }
    @keyframes flash-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    .flash-toast--error   { background:#fef2f2; border-color:#fca5a5; color:#b91c1c; }
    .flash-toast--warning { background:#fffbeb; border-color:#fcd34d; color:#b45309; }
    .flash-toast--success { background:#f0fdf4; border-color:#86efac; color:#15803d; }
    .flash-toast__body { flex:1; font-weight:600; }
    .flash-toast__list { margin:0.35rem 0 0; padding-left:1.1rem; font-weight:500; }
    .flash-toast__list li { list-style:disc; }
    .flash-toast__close {
        background:none; border:none; cursor:pointer; font-size:1.1rem; line-height:1;
        color:inherit; opacity:0.6; padding:0; margin-left:0.15rem;
    }
    .flash-toast__close:hover { opacity:1; }
</style>
@endif
