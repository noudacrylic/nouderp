@extends('layouts.cs')

@section('title', 'Notifikasi — NOUD Chat')

@section('topbar')
    <div class="px-4 py-3.5 flex items-center justify-between">
        <h1 class="text-lg font-extrabold">Notifikasi</h1>
        @if ($daftar->contains(fn ($n) => ! $n->sudahDibaca()))
            <button type="button" id="cs-baca-semua"
                    class="text-[11px] font-semibold bg-white/15 active:bg-white/25 rounded-full px-3 py-1.5">
                Tandai semua dibaca
            </button>
        @endif
    </div>
@endsection

@section('content')
    @forelse ($daftar as $n)
        @php $baru = ! $n->sudahDibaca(); @endphp
        <a href="{{ $n->url ?: url('/cs') }}"
           data-notif-id="{{ $n->id }}"
           class="cs-notif flex gap-3 px-4 py-3 border-b border-slate-100 active:bg-slate-100 {{ $baru ? 'bg-teal-50/60' : 'bg-white' }}">
            <span class="mt-0.5 w-2 h-2 shrink-0 rounded-full {{ $baru ? 'bg-teal-600' : 'bg-transparent' }}"></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-slate-800 truncate">{{ $n->judul }}</p>
                <p class="text-xs text-slate-500 line-clamp-2 mt-0.5">{{ $n->isi }}</p>
                <p class="text-[11px] text-slate-400 mt-1">{{ $n->updated_at?->diffForHumans() }}</p>
            </div>
        </a>
    @empty
        <div class="h-full flex items-center justify-center p-8 text-center">
            <div>
                <p class="text-5xl mb-3">🔔</p>
                <p class="text-sm font-bold text-slate-700 mb-1">Belum ada notifikasi</p>
                <p class="text-xs text-slate-500">Chat masuk dan pengingat akan muncul di sini.</p>
            </div>
        </div>
    @endforelse
@endsection

@push('scripts')
<script>
(function () {
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const kirim = (url) => fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
    });

    // Tandai terbaca saat diklik. Sengaja TIDAK menunggu jawabannya sebelum
    // berpindah halaman: yang penting bagi CS adalah chatnya terbuka cepat,
    // dan tanda terbaca yang telat sedetik tidak merugikan siapa pun.
    document.querySelectorAll('.cs-notif').forEach((el) => {
        el.addEventListener('click', () => {
            kirim(@json(url('/erp/notifikasi')) + '/' + el.dataset.notifId + '/baca').catch(() => {});
        });
    });

    document.getElementById('cs-baca-semua')?.addEventListener('click', async (e) => {
        e.currentTarget.disabled = true;
        try { await kirim(@json(route('notifikasi.baca-semua'))); location.reload(); }
        catch (_) { e.currentTarget.disabled = false; }
    });
})();
</script>
@endpush
