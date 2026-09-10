@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">WhatsApp Notifikasi — WAHA</h2>
        <p class="text-sm text-gray-500 mb-5">
            Jalur <b>tak resmi</b> yang berjalan di server sendiri: WAHA menautkan diri sebagai perangkat
            ke sebuah nomor WhatsApp biasa, persis seperti WhatsApp Web. Dipakai hanya untuk notifikasi
            pesanan (pembayaran, siap diambil, resi) &mdash; chat pelanggan tetap di jalur resmi
            <a href="{{ route('settings.crm.edit') }}" class="text-blue-600 hover:underline">api.co.id</a>.
        </p>

        {{-- ===== Keadaan sesi ===== --}}
        @php
            $statusTerakhir = $terakhir['status'];
            $sesiSiap       = $statusTerakhir === 'WORKING';
        @endphp

        <div class="mb-5 rounded-lg border px-4 py-3 text-sm
            {{ $sesiSiap ? 'border-green-200 bg-green-50 text-green-800' : ($statusTerakhir ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-600') }}">
            @if($statusTerakhir)
                <b>Status sesi terakhir: {{ $statusTerakhir }}</b>
                @if($sesiSiap)
                    &mdash; nomor tertaut &amp; siap mengirim.
                @else
                    &mdash; notifikasi lewat jalur ini sedang <b>tidak berangkat</b>.
                @endif
                <span class="block text-xs opacity-75 mt-1">
                    Diperiksa {{ $terakhir['waktu'] ? $terakhir['waktu']->diffForHumans() : '—' }}.
                    Angka ini hasil pemeriksaan terakhir, bukan keadaan detik ini &mdash; tekan
                    <b>Uji Sesi</b> untuk yang terbaru.
                </span>
            @else
                Sesi belum pernah diperiksa. Tekan <b>Uji Sesi</b> di bawah.
            @endif
        </div>

        {{-- Pengaman global tinggal di layar api.co.id: satu daftar putih & satu
             saklar untuk kedua jalur. Di sini cukup ditampilkan supaya orang tak
             mengira WAHA berjalan tanpa penjaga. --}}
        <div class="mb-5 rounded-lg border {{ $dryRun ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-600' }} px-4 py-3 text-sm">
            @if($dryRun)
                <b>Mode jangan-kirim aktif</b> &mdash; tidak ada notifikasi yang benar-benar terkirim,
                termasuk lewat WAHA.
            @else
                Pengiriman nyata aktif,
                {{ $daftarPutih ? 'terbatas ' . count($daftarPutih) . ' nomor di daftar putih.' : 'TANPA daftar putih penerima.' }}
            @endif
            <span class="block text-xs opacity-75 mt-1">
                Saklar &amp; daftar putih berlaku untuk kedua jalur, dan diatur di
                <a href="{{ route('settings.crm.edit') }}" class="underline font-semibold">Pengaturan CRM WhatsApp</a>.
            </span>
        </div>

        <form method="POST" action="{{ route('settings.waha.update') }}" class="space-y-6">
            @csrf

            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Kredensial &amp; Jalur</h3>

                <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                    Chat dan notifikasi dipisah berdasarkan <b>peran</b>, bukan vendor. Notifikasi pesanan
                    &mdash; yang volumenya paling besar tapi isinya paling sederhana &mdash; boleh dialihkan ke
                    jalur ini. Bunyi kalimatnya sama persis dengan template yang disetujui Meta, jadi
                    pelanggan tidak bisa membedakan jalur mana yang dipakai.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
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
                            Pindah ke WAHA baru sah setelah sesinya benar-benar tertaut &mdash; uji dengan tombol di bawah.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nama Sesi WAHA</label>
                        <input type="text" name="waha_session"
                               value="{{ old('waha_session', $waha->config['session'] ?? 'notifikasi') }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm">
                        <p class="text-xs text-gray-400 mt-1">
                            Dua sesi dijalankan: nomor aktif + nomor cadangan yang sudah dipanaskan. Ganti nama di sini
                            bila nomor utama kena blokir.
                        </p>
                    </div>

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
                </div>
            </div>

            <div class="pt-1">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        {{-- ===== Penautan nomor (QR) ===== --}}
        <hr class="my-6">
        <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Nomor WhatsApp</h3>

        <p class="text-xs text-gray-500 mb-4 leading-relaxed">
            QR-nya diambilkan ERP langsung dari WAHA, jadi menautkan atau mengganti nomor tidak lagi
            menuntut SSH ke server. WAHA-nya sendiri tetap tertutup di 127.0.0.1.
            Pindai dari HP pemegang nomor: <b>WhatsApp → Perangkat Tertaut → Tautkan Perangkat</b>.
        </p>

        <div class="flex flex-wrap gap-2 mb-4">
            <form method="POST" action="{{ route('settings.waha.uji') }}">
                @csrf
                <button type="submit" class="px-3 py-2 border border-gray-300 hover:bg-gray-50 rounded text-sm font-semibold text-gray-700">Uji Sesi</button>
            </form>
            <form method="POST" action="{{ route('settings.waha.tautkan') }}">
                @csrf
                <button type="submit" class="px-3 py-2 border border-emerald-300 text-emerald-700 hover:bg-emerald-50 rounded text-sm font-semibold">Tautkan Nomor (QR)</button>
            </form>
            <form method="POST" action="{{ route('settings.waha.putuskan') }}"
                  onsubmit="return confirm('Putuskan nomor yang sekarang tertaut? Notifikasi lewat WAHA berhenti sampai QR baru dipindai.')">
                @csrf
                <button type="submit" class="px-3 py-2 border border-red-300 text-red-700 hover:bg-red-50 rounded text-sm font-semibold">Putuskan &amp; Ganti Nomor</button>
            </form>
        </div>

        @if(request('qr'))
            {{-- QR berputar tiap ±20 detik; gambarnya disegarkan sendiri, dan
                 status di-polling supaya panel tahu kapan berhenti menyuruh
                 orang memindai. --}}
            <div id="panel-qr" class="rounded-lg border bg-gray-50 p-4 text-center">
                <img id="gambar-qr" src="{{ route('settings.waha.qr') }}?t={{ time() }}" alt="QR WAHA"
                     class="mx-auto w-64 h-64 bg-white border rounded"
                     onerror="this.style.display='none'; document.getElementById('qr-gagal').style.display='block';">
                <p id="qr-gagal" style="display:none" class="text-sm text-amber-700">
                    QR belum bisa diambil. Sesi mungkin sudah tertaut (tidak perlu QR), atau WAHA sedang tak menjawab —
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
                var qrUrl  = @json(route('settings.waha.qr'));
                var cekUrl = @json(route('settings.waha.status'));
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
                                selesai('Nomor tertaut — sesi berstatus WORKING. Notifikasi sudah bisa berangkat.');
                                return;
                            }
                            label.textContent = 'Menunggu dipindai… (status ' + d.status + ')';
                        })
                        .catch(function () { /* WAHA sedang tak menjawab; biarkan siklus berikutnya mencoba lagi. */ });
                }, 5000);
            })();
            </script>
        @endif

        <p class="text-xs text-gray-400 mt-4">
            Sesi bisa putus tanpa gejala apa pun (HP mati, WhatsApp mengeluarkan perangkat tertaut) &mdash;
            ERP tetap mengantrekan notifikasi dan layarnya tetap rapi, tapi pelanggan tidak menerima apa pun.
            Karena itu pemeriksa berkala mengabarkan putusnya sesi ke Telegram, lengkap dengan QR-nya.
        </p>
    </div>
</div>
@endsection
