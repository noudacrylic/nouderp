@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">WhatsApp Self-Host — WAHA</h2>
        <p class="text-sm text-gray-500 mb-5">
            Jalur <b>tak resmi</b> yang berjalan di server sendiri: WAHA menautkan diri sebagai perangkat
            ke sebuah nomor WhatsApp biasa, persis seperti WhatsApp Web &mdash; HP pemegang nomor tetap
            bisa dipakai seperti biasa. Satu container melayani <b>dua nomor</b>: nomor utama tempat
            pelanggan chat, dan nomor notifikasi yang mengirim kabar pesanan.
        </p>

        {{-- Pengaman global tinggal di layar api.co.id: satu daftar putih & satu
             saklar untuk semua jalur. Di sini cukup ditampilkan supaya orang tak
             mengira WAHA berjalan tanpa penjaga. --}}
        <div class="mb-5 rounded-lg border {{ $dryRun ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-600' }} px-4 py-3 text-sm">
            @if($dryRun)
                <b>Mode jangan-kirim aktif</b> &mdash; tidak ada pesan yang benar-benar terkirim,
                termasuk lewat WAHA.
            @else
                Pengiriman nyata aktif,
                {{ $daftarPutih ? 'terbatas ' . count($daftarPutih) . ' nomor di daftar putih.' : 'TANPA daftar putih penerima.' }}
            @endif
            <span class="block text-xs opacity-75 mt-1">
                Saklar &amp; daftar putih berlaku untuk semua jalur, dan diatur di
                <a href="{{ route('settings.crm.edit') }}" class="underline font-semibold">Pengaturan CRM WhatsApp</a>.
            </span>
        </div>

        <form method="POST" action="{{ route('settings.waha.update') }}" class="space-y-6">
            @csrf

            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Sambungan &amp; Jalur</h3>

                <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                    Alamat &amp; API key dipakai bersama kedua nomor &mdash; container-nya memang satu.
                    Yang berdiri sendiri per nomor adalah <b>nama sesi</b>, dan itulah yang menentukan
                    pesan berangkat dari nomor yang mana.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Alamat WAHA</label>
                        <input type="text" name="waha_base_url"
                               value="{{ old('waha_base_url', $waha->base_url) }}"
                               placeholder="http://127.0.0.1:3000"
                               class="w-full border rounded px-3 py-2 font-mono text-sm">
                        <p class="text-xs text-amber-700 mt-1">
                            <b>Wajib 127.0.0.1.</b> API key WAHA = kunci penuh sebuah akun WhatsApp, dan instance
                            WAHA terbuka rutin dipindai bot. Jangan pernah disambungkan ke Cloudflare Tunnel.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">API Key WAHA</label>
                        <input type="password" name="waha_api_key" autocomplete="new-password"
                               placeholder="{{ $waha->api_key ? 'Tersimpan — kosongkan bila tidak diubah' : 'Belum diisi' }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm">
                        <label class="inline-flex items-start gap-2 text-sm font-semibold text-gray-700 mt-3">
                            <input type="checkbox" name="waha_enabled" value="1"
                                   {{ old('waha_enabled', $waha->is_enabled) ? 'checked' : '' }}
                                   class="rounded border-gray-300 mt-0.5">
                            <span>Aktifkan WAHA</span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nama Sesi &mdash; Nomor Utama</label>
                        <input type="text" name="waha_session_utama"
                               value="{{ old('waha_session_utama', $panel['utama']['nama_sesi']) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm">
                        <p class="text-xs text-gray-400 mt-1">Sesi nomor yang dipajang ke pelanggan.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nama Sesi &mdash; Nomor Notifikasi</label>
                        <input type="text" name="waha_session_notifikasi"
                               value="{{ old('waha_session_notifikasi', $panel['notifikasi']['nama_sesi']) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm">
                        <p class="text-xs text-gray-400 mt-1">
                            Wajib berbeda dari sesi nomor utama &mdash; itulah yang membuat nomor utama tidak
                            ikut menanggung risiko blokir.
                        </p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Kirim Notifikasi Lewat</label>
                        <select name="notifikasi_driver" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="resmi" @selected(old('notifikasi_driver', $driver) === 'resmi')>
                                Template resmi Meta (berbayar)
                            </option>
                            <option value="waha" @selected(old('notifikasi_driver', $driver) === 'waha')>
                                WAHA &mdash; self-host, gratis (tak resmi)
                            </option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">
                            Hanya mengatur <b>notifikasi pesanan</b>, dan hanya lewat nomor notifikasi. Chat
                            pelanggan tidak ikut pindah karena pilihan ini.
                        </p>
                    </div>
                </div>
            </div>

            <div class="pt-1">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        {{-- ===== Satu panel per nomor ===== --}}
        <hr class="my-6">
        <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-1">Nomor WhatsApp</h3>
        <p class="text-xs text-gray-500 mb-4 leading-relaxed">
            QR-nya diambilkan ERP langsung dari WAHA, jadi menautkan atau mengganti nomor tidak lagi
            menuntut SSH ke server. WAHA-nya sendiri tetap tertutup di 127.0.0.1.
            Pindai dari HP pemegang nomor: <b>WhatsApp → Perangkat Tertaut → Tautkan Perangkat</b>.
        </p>

        @foreach($panel as $peran => $p)
            @php
                $siap = $p['status'] === 'WORKING';
                $warna = $siap
                    ? 'border-green-200 bg-green-50 text-green-800'
                    : ($p['status'] ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-600');
            @endphp

            <div class="mb-5 rounded-lg border border-gray-200 p-4">
                <div class="flex items-start justify-between gap-3 mb-2">
                    <div>
                        <h4 class="text-sm font-bold text-gray-800">{{ $p['label'] }}</h4>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Sesi <span class="font-mono">{{ $p['nama_sesi'] }}</span>
                        </p>
                    </div>
                    @unless($p['tertaut'])
                        <span class="shrink-0 text-xs px-2 py-1 rounded bg-gray-100 text-gray-500 font-semibold">Belum pernah ditautkan</span>
                    @endunless
                </div>

                <p class="text-xs text-gray-500 mb-3 leading-relaxed">{{ $p['penjelasan'] }}</p>

                <div class="rounded border px-3 py-2 text-sm mb-3 {{ $warna }}">
                    @if($p['status'])
                        <b>Status sesi terakhir: {{ $p['status'] }}</b>
                        @if($siap)
                            &mdash; nomor tertaut.
                        @else
                            &mdash; nomor ini sedang <b>tidak melayani apa pun</b>.
                        @endif
                        <span class="block text-xs opacity-75 mt-1">
                            Diperiksa {{ $p['diperiksa'] ? $p['diperiksa']->diffForHumans() : '—' }}.
                            Angka ini hasil pemeriksaan terakhir, bukan keadaan detik ini &mdash; tekan
                            <b>Uji Sesi</b> untuk yang terbaru.
                        </span>
                    @else
                        Sesi belum pernah diperiksa. Tekan <b>Uji Sesi</b> di bawah.
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('settings.waha.uji', ['peran' => $peran]) }}">
                        @csrf
                        <button type="submit" class="px-3 py-2 border border-gray-300 hover:bg-gray-50 rounded text-sm font-semibold text-gray-700">Uji Sesi</button>
                    </form>
                    <form method="POST" action="{{ route('settings.waha.tautkan', ['peran' => $peran]) }}">
                        @csrf
                        <button type="submit" class="px-3 py-2 border border-emerald-300 text-emerald-700 hover:bg-emerald-50 rounded text-sm font-semibold">Tautkan Nomor (QR)</button>
                    </form>
                    <form method="POST" action="{{ route('settings.waha.putuskan', ['peran' => $peran]) }}"
                          onsubmit="return confirm('Putuskan nomor yang sekarang tertaut di {{ $p['label'] }} (sesi {{ $p['nama_sesi'] }})? Nomor ini berhenti melayani sampai QR baru dipindai.')">
                        @csrf
                        <button type="submit" class="px-3 py-2 border border-red-300 text-red-700 hover:bg-red-50 rounded text-sm font-semibold">Putuskan &amp; Ganti Nomor</button>
                    </form>
                </div>

                @if($qrPeran === $peran)
                    {{-- QR berputar tiap ±20 detik; gambarnya disegarkan sendiri, dan
                         status di-polling supaya panel tahu kapan berhenti menyuruh
                         orang memindai. Hanya SATU panel QR yang pernah terbuka —
                         dua QR berdampingan membuat orang memindai milik nomor
                         yang salah, dan itu menukar kedua nomor. --}}
                    <div class="mt-4 rounded-lg border bg-gray-50 p-4 text-center">
                        <p class="text-sm font-semibold text-gray-700 mb-2">
                            Pindai dari HP pemegang {{ $p['label'] }}
                        </p>
                        <img id="gambar-qr" src="{{ route('settings.waha.qr', ['peran' => $peran]) }}?t={{ time() }}" alt="QR WAHA"
                             class="mx-auto w-64 h-64 bg-white border rounded"
                             onerror="this.style.display='none'; document.getElementById('qr-gagal').style.display='block';">
                        <p id="qr-gagal" style="display:none" class="text-sm text-amber-700">
                            QR belum bisa diambil. Sesi mungkin sudah tertaut (tidak perlu QR), atau WAHA sedang tak menjawab &mdash;
                            tekan <b>Uji Sesi</b> untuk memastikan.
                        </p>
                        <p id="qr-status" class="text-xs text-gray-500 mt-3">
                            Menunggu dipindai… QR disegarkan otomatis tiap 20 detik.
                        </p>
                    </div>

                    <script>
                    (function () {
                        var img    = document.getElementById('gambar-qr');
                        var label  = document.getElementById('qr-status');
                        var qrUrl  = @json(route('settings.waha.qr', ['peran' => $peran]));
                        var cekUrl = @json(route('settings.waha.status', ['peran' => $peran]));
                        var segar, cek;

                        function selesai(pesan) {
                            clearInterval(segar);
                            clearInterval(cek);
                            if (img) { img.style.display = 'none'; }
                            label.className = 'text-sm font-semibold text-green-700 mt-3';
                            label.textContent = pesan;
                        }

                        segar = setInterval(function () {
                            if (img) { img.src = qrUrl + '?t=' + Date.now(); img.style.display = ''; }
                        }, 20000);

                        cek = setInterval(function () {
                            fetch(cekUrl, { headers: { 'Accept': 'application/json' } })
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    if (d.siap) {
                                        selesai('Nomor tertaut — sesi berstatus WORKING.');
                                        return;
                                    }
                                    label.textContent = 'Menunggu dipindai… (status ' + d.status + ')';
                                })
                                .catch(function () { /* WAHA sedang tak menjawab; biarkan siklus berikutnya mencoba lagi. */ });
                        }, 5000);
                    })();
                    </script>
                @endif
            </div>
        @endforeach

        <p class="text-xs text-gray-400 mt-4">
            Sesi bisa putus tanpa gejala apa pun (HP mati, WhatsApp mengeluarkan perangkat tertaut) &mdash;
            layar ERP tetap rapi, tapi nomornya diam. Karena itu pemeriksa berkala mengabarkan putusnya
            sesi ke Telegram, lengkap dengan QR-nya dan <b>nomor mana</b> yang harus dipindai.
        </p>
    </div>
</div>
@endsection
