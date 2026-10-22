@extends('layouts.cs', ['tanpaNav' => true, 'mainKelas' => 'overflow-hidden'])

@section('title', ($lampiran->original_name ?: 'Lampiran') . ' — NOUD Chat')

@section('topbar')
    <div class="flex items-center gap-2 px-2 h-14">
        {{-- Kembali ke chat asalnya. history.back() lebih dulu supaya posisi gulir
             percakapan tidak hilang; tanpa riwayat (dibuka langsung) ke daftar chat. --}}
        <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '{{ route('cs.chat') }}')"
                class="w-10 h-10 shrink-0 text-xl leading-none">←</button>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold">{{ $lampiran->original_name ?: 'Lampiran PDF' }}</div>
            <div class="text-[11px] text-white/70" id="pdf-status">Memuat…</div>
        </div>
        <button type="button" id="pdf-kecil" class="w-9 h-9 shrink-0 rounded bg-white/10 text-lg leading-none">−</button>
        <button type="button" id="pdf-besar" class="w-9 h-9 shrink-0 rounded bg-white/10 text-lg leading-none">+</button>
        <a href="{{ route('cs.lampiran.berkas', $lampiran->id) }}" download="{{ $lampiran->original_name }}"
           class="w-9 h-9 shrink-0 rounded bg-white/10 flex items-center justify-center" title="Simpan ke HP">⬇</a>
    </div>
@endsection

@section('content')
    <div id="pdf-wadah" class="h-full overflow-auto bg-slate-300 p-2 space-y-2"></div>
@endsection

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        (function () {
            var wadah  = document.getElementById('pdf-wadah');
            var status = document.getElementById('pdf-status');
            var berkas = @json(route('cs.lampiran.berkas', $lampiran->id));
            var dok = null;
            var zoom = 1;

            if (! window.pdfjsLib) {
                status.textContent = 'Penampil PDF gagal dimuat — tekan ⬇ untuk menyimpan berkasnya.';
                return;
            }

            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

            var giliran = 0;

            async function gambar() {
                // Tombol zoom ditekan beruntun: gambaran lama berhenti, bukan
                // menyelipkan halamannya di antara halaman gambaran baru.
                var ini = ++giliran;
                wadah.innerHTML = '';
                var lebar = (wadah.clientWidth - 16) * zoom;
                var rasio = window.devicePixelRatio || 1;

                for (var i = 1; i <= dok.numPages; i++) {
                    var hal = await dok.getPage(i);
                    if (ini !== giliran) return;
                    var dasar = hal.getViewport({ scale: 1 });
                    var vp = hal.getViewport({ scale: (lebar / dasar.width) * rasio });

                    var kanvas = document.createElement('canvas');
                    kanvas.width = vp.width;
                    kanvas.height = vp.height;
                    kanvas.style.width = (vp.width / rasio) + 'px';
                    kanvas.style.height = (vp.height / rasio) + 'px';
                    kanvas.className = 'block bg-white shadow mx-auto';
                    wadah.appendChild(kanvas);

                    await hal.render({ canvasContext: kanvas.getContext('2d'), viewport: vp }).promise;
                }
            }

            // Berkas diambil lewat fetch biasa: service worker /cs menyimpannya di HP,
            // sehingga pembukaan berikutnya langsung dari cache tanpa unduh ulang.
            fetch(berkas, { credentials: 'same-origin' })
                .then(function (r) {
                    if (! r.ok) throw new Error('HTTP ' + r.status);
                    return r.arrayBuffer();
                })
                .then(function (data) { return pdfjsLib.getDocument({ data: data }).promise; })
                .then(function (d) {
                    dok = d;
                    status.textContent = d.numPages + ' halaman';
                    return gambar();
                })
                .catch(function (e) {
                    status.textContent = 'Gagal membuka PDF (' + e.message + ').';
                });

            document.getElementById('pdf-besar').addEventListener('click', function () {
                if (! dok || zoom >= 3) return;
                zoom = Math.min(3, zoom + 0.5);
                gambar();
            });
            document.getElementById('pdf-kecil').addEventListener('click', function () {
                if (! dok || zoom <= 1) return;
                zoom = Math.max(1, zoom - 0.5);
                gambar();
            });
        })();
    </script>
@endpush
