@extends('layouts.erp')

@section('content')
{{--
    QR untuk stiker produk.

    Dibangkitkan di browser (pustaka dari CDN, sama pola dengan Trix & Alpine di
    ERP) supaya tidak menambah dependensi PHP untuk sesuatu yang dipakai
    sesekali oleh admin.

    Yang disandikan HURUF BESAR — lihat StoreTutorial::qrPayload(). QR punya mode
    alfanumerik khusus yang memuat huruf besar, angka, ":" dan "/", dan mode itu
    jauh lebih padat daripada mode teks biasa. Alamat yang sama turun satu tingkat
    versi (29x29 kotak, bukan 33x33), sehingga pada stiker seukuran sama tiap
    kotaknya ~11% lebih besar — itulah yang menentukan berhasil-tidaknya scan di
    permukaan akrilik yang mengkilap.
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
        <p class="text-[11px] text-gray-400 mt-2">2000 px — cukup tajam untuk stiker 3 cm.</p>
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
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
(function () {
    const payload  = @json($tutorial->qrPayload());   // HURUF BESAR — lihat catatan di atas
    const label    = @json($tutorial->shortUrl());    // huruf kecil, untuk mata manusia
    const previewC = document.getElementById('qrPreview');
    const link     = document.getElementById('qrDownload');

    const opts = { errorCorrectionLevel: 'H', margin: 4, color: { dark: '#000000', light: '#ffffff' } };

    QRCode.toCanvas(previewC, payload, { ...opts, width: 480 }, function (err) {
        if (err) console.error(err);
    });

    // Berkas cetak: QR besar + alamat di bawahnya, jadi satu gambar yang bisa
    // langsung dikirim ke tukang stiker tanpa perlu menata apa pun lagi.
    QRCode.toDataURL(payload, { ...opts, width: 2000 }, function (err, url) {
        if (err) { console.error(err); return; }

        const img = new Image();
        img.onload = function () {
            const pad   = 60;
            const textH = 200;
            const c = document.createElement('canvas');
            c.width  = img.width;
            c.height = img.height + textH;

            const ctx = c.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(img, 0, 0);

            ctx.fillStyle = '#000000';
            ctx.textAlign = 'center';
            ctx.font = 'bold 96px ui-monospace, "Courier New", monospace';
            ctx.fillText(label, c.width / 2, img.height + textH - pad);

            link.href = c.toDataURL('image/png');
        };
        img.src = url;
    });
})();
</script>
@endpush
@endsection
