@extends('layouts.erp')

@section('content')
{{--
    QR untuk stiker produk.

    Dibangkitkan di browser (pustaka dari CDN, sama pola dengan Trix & Alpine di
    ERP) supaya tidak menambah dependensi PHP untuk sesuatu yang dipakai
    sesekali oleh admin.

    Yang disandikan PERSIS seperti yang tercetak: huruf kecil. Pernah dibuat huruf
    besar demi mode alfanumerik QR yang lebih padat — dan hasilnya 404, karena
    path alamat peduli besar-kecil huruf ("/T/TB1" bukan "/t/tb1"). Kepadatannya
    diambil kembali lewat tingkat koreksi galat 'Q' di bawah, bukan lewat huruf.
--}}

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">QR Stiker — {{ $tutorial->title }}</h1>
        <p class="text-xs text-gray-500">Kode <span class="font-mono font-bold">{{ $tutorial->code }}</span></p>
    </div>
    <a href="{{ route('store.tutorials.edit', $tutorial->id) }}" class="text-sm text-gray-500 hover:text-gray-700">← Kembali</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="bg-white rounded shadow p-6 flex flex-col items-center justify-center">
        <canvas id="qrPreview" class="border rounded" style="width:240px;height:auto"></canvas>
        <div class="mt-3 font-mono text-sm text-gray-700">{{ $tutorial->shortUrl() }}</div>
        <a id="qrDownload" download="stiker-{{ $tutorial->code }}.png"
           class="mt-4 bg-blue-600 text-white px-4 py-2 rounded text-sm cursor-pointer">Unduh PNG siap cetak</a>
        <p id="qrUkuran" class="text-[11px] text-gray-400 mt-2">Menyiapkan berkas cetak…</p>
    </div>

    <div class="lg:col-span-2 space-y-4">
        <div class="bg-white rounded shadow p-4 text-sm text-gray-700 space-y-3">
            <div class="font-semibold text-gray-800">Cara mencetaknya</div>

            <p><b>Ukuran minimal 3 cm.</b> QR-nya sendiri butuh sekitar 2,1 cm agar tiap kotaknya
            ≥ 0,5 mm dan terbaca kamera ponsel dari jarak wajar; sisanya untuk alamat tercetak di
            bawahnya. Godaan mengecilkannya jadi 1,5 cm supaya lebih rapi adalah penyebab paling
            umum QR gagal di-scan — tolak.</p>

            <p><b>Pilih bahan matte/doff, jangan glossy.</b> Stiker mengkilap di atas akrilik yang
            juga mengkilap menghasilkan pantulan yang membuat kamera gagal fokus. Pengaruhnya jauh
            lebih besar daripada yang orang kira.</p>

            <p><b>Biarkan margin putihnya.</b> Ruang kosong di sekeliling QR itu bagian dari
            kodenya, bukan hiasan — memangkasnya membuat pemindai gagal mengenali batas.</p>

            <p><b>Alamatnya ikut tercetak</b> di bawah QR. Itu jaring pengaman kalau kamera gagal,
            sekaligus bagian yang membangun kepercayaan: orang membaca
            <span class="font-mono">noudakrilik.com</span> dan tahu tautan ini milik Anda —
            hal yang tidak pernah diberikan bit.ly.</p>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
            <div class="font-semibold mb-1">Sebelum cetak banyak</div>
            Terbitkan dulu tutorialnya, lalu <b>scan sendiri stiker contohnya</b> dengan dua ponsel
            berbeda — satu Android, satu iPhone kalau ada. Kode
            <span class="font-mono">{{ $tutorial->code }}</span> tidak bisa diubah lagi setelah
            stikernya menempel di barang yang sudah dikirim.
        </div>
    </div>
</div>

@push('scripts')
{{-- Pembangkit QR.

     JANGAN kembali ke paket npm `qrcode`: paket itu TIDAK menerbitkan bundel
     browser sama sekali — `qrcode@1.5.4/build/qrcode.min.js` menjawab 404 di
     jsDelivr maupun unpkg, dan itulah sebabnya kotak QR di halaman ini pernah
     kosong tanpa pesan galat apa pun.

     `qrcode-generator` dipilih karena membuka jumlah modulnya (`getModuleCount`),
     jadi matriksnya bisa digambar sendiri: zona sunyi PERSIS 4 modul dan ukuran
     modul selalu bilangan bulat piksel. Dua hal itu yang menentukan stiker 3 cm
     terbaca atau tidak — pustaka yang menghitung padding sendiri gampang meleset
     setengah piksel dan membuat modulnya kabur saat dicetak. --}}
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
<script>
(function () {
    const payload  = @json($tutorial->qrPayload());   // huruf kecil — sama persis dengan yang tercetak
    const label    = @json($tutorial->shortUrl());    // huruf kecil, untuk mata manusia
    const previewC = document.getElementById('qrPreview');
    const link     = document.getElementById('qrDownload');

    // Gagal memuat pustaka harus KELIHATAN. Sebelumnya diam-diam kosong.
    function gagal(pesan) {
        const p = document.createElement('p');
        p.className = 'text-sm text-red-600 text-center';
        p.textContent = pesan;
        previewC.replaceWith(p);
        link.remove();
        document.getElementById('qrUkuran')?.remove();
    }
    if (typeof qrcode !== 'function') {
        gagal('Pustaka QR gagal dimuat. Periksa sambungan internet lalu muat ulang halaman.');
        return;
    }

    const QUIET = 4;   // zona sunyi wajib QR — bagian dari kodenya, bukan hiasan.

    let qr;
    try {
        // 'Q' (koreksi galat 25%), bukan 'H' (30%). Alamat sepanjang
        // "https://noudakrilik.com/t/tb1" masih muat di versi 3 (29x29 kotak)
        // dengan 'Q', sedangkan 'H' mendorongnya ke versi 4 (33x33) — tiap
        // kotak ~11% lebih kecil pada stiker 3 cm, dan ukuran kotak itulah yang
        // menentukan berhasil-tidaknya scan di akrilik yang mengkilap. 25% masih
        // jauh di atas kebutuhan stiker yang tidak ditimpa logo apa pun.
        qr = qrcode(0, 'Q');       // 0 = pilih versi terkecil yang muat
        qr.addData(payload);
        qr.make();
    } catch (e) {
        console.error(e);
        gagal('Alamat tutorial ini tidak bisa dijadikan QR.');
        return;
    }

    const modul = qr.getModuleCount();
    const total = modul + QUIET * 2;

    /** Gambar QR ke kanvas; skala dibulatkan ke bawah agar tiap modul utuh. */
    function gambar(canvas, targetPx) {
        const skala = Math.max(1, Math.floor(targetPx / total));
        const sisi  = total * skala;
        canvas.width = sisi;
        canvas.height = sisi;

        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, sisi, sisi);
        ctx.fillStyle = '#000000';
        for (let baris = 0; baris < modul; baris++) {
            for (let kolom = 0; kolom < modul; kolom++) {
                if (qr.isDark(baris, kolom)) {
                    ctx.fillRect((kolom + QUIET) * skala, (baris + QUIET) * skala, skala, skala);
                }
            }
        }
        return sisi;
    }

    gambar(previewC, 480);

    // Berkas cetak: QR besar + alamat di bawahnya, jadi satu gambar yang bisa
    // langsung dikirim ke tukang stiker tanpa perlu menata apa pun lagi.
    const cetak = document.createElement('canvas');
    const sisi  = gambar(cetak, 2000);
    const textH = Math.round(sisi * 0.1);

    const gabung = document.createElement('canvas');
    gabung.width  = sisi;
    gabung.height = sisi + textH;
    const ctx = gabung.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, gabung.width, gabung.height);
    ctx.drawImage(cetak, 0, 0);
    ctx.fillStyle = '#000000';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = 'bold ' + Math.round(textH * 0.42) + 'px ui-monospace, "Courier New", monospace';
    ctx.fillText(label, gabung.width / 2, sisi + textH / 2);

    link.href = gabung.toDataURL('image/png');

    // Ukurannya tidak bisa dipatok 2000 px persis: skala modul dibulatkan ke
    // bawah supaya tiap kotak utuh, jadi angkanya diberitahukan apa adanya.
    const ket = document.getElementById('qrUkuran');
    if (ket) ket.textContent = sisi + ' px — cukup tajam untuk stiker 3 cm.';
})();
</script>
@endpush
@endsection
