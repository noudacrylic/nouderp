/*
 * Foto nota: perkecil foto di peramban sebelum diunggah.
 *
 * Foto ponsel 3-5 MB → JPEG sisi panjang 1600 px (±200-400 KB). Unggahan dari
 * Wi-Fi gudang jadi sekejap, dan server tetap memperkecil lagi bila ada yang
 * lolos (FotoNotaService). PDF & format yang tak bisa digambar ulang (HEIC di
 * peramban non-Safari) dikirim apa adanya.
 *
 * Pemakaian: <input type="file" data-foto-nota [data-auto-submit]>.
 *  - data-auto-submit → form langsung dikirim setelah foto diproses (kartu
 *    foto nota di halaman detail: satu ketukan, selesai).
 *  - tanpa itu → hanya mengganti berkas & menampilkan ringkasan di
 *    [data-foto-nota-info] dalam form (form input dokumen).
 */
(function () {
    'use strict';

    const MAKS_SISI = 1600;
    const MUTU = 0.82;
    const BISA_DIGAMBAR = ['image/jpeg', 'image/png', 'image/webp'];

    async function perkecil(file) {
        if (!BISA_DIGAMBAR.includes(file.type) || !window.createImageBitmap) return file;
        try {
            const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const skala = Math.min(1, MAKS_SISI / Math.max(bmp.width, bmp.height));
            if (skala === 1 && file.size <= 400 * 1024) { bmp.close && bmp.close(); return file; }
            const w = Math.round(bmp.width * skala), h = Math.round(bmp.height * skala);
            const kanvas = document.createElement('canvas');
            kanvas.width = w; kanvas.height = h;
            const c = kanvas.getContext('2d');
            c.fillStyle = '#fff';            // PNG transparan → latar putih, bukan hitam
            c.fillRect(0, 0, w, h);
            c.drawImage(bmp, 0, 0, w, h);
            bmp.close && bmp.close();
            const blob = await new Promise(res => kanvas.toBlob(res, 'image/jpeg', MUTU));
            if (!blob || blob.size >= file.size) return file;
            const nama = file.name.replace(/\.[^.]+$/, '') + '.jpg';
            return new File([blob], nama, { type: 'image/jpeg', lastModified: Date.now() });
        } catch (e) {
            return file;
        }
    }

    function info(input, teks) {
        const form = input.form;
        if (!form) return;
        form.querySelectorAll('[data-foto-nota-info]').forEach(el => { el.textContent = teks; });
    }

    function jumlahDipilih(form) {
        let n = 0;
        form.querySelectorAll('input[data-foto-nota]').forEach(i => { n += i.files ? i.files.length : 0; });
        return n;
    }

    document.addEventListener('change', async (e) => {
        const input = e.target;
        if (!input.matches || !input.matches('input[type="file"][data-foto-nota]')) return;
        if (!input.files || !input.files.length) return;

        const otomatis = input.hasAttribute('data-auto-submit');
        info(input, 'Memproses foto…');
        if (otomatis) {
            input.form && input.form.querySelectorAll('[data-foto-nota-tombol]').forEach(b => {
                b.classList.add('opacity-50', 'pointer-events-none');
            });
        }

        const hasil = await Promise.all(Array.from(input.files).map(perkecil));
        if (window.DataTransfer) {
            try {
                const dt = new DataTransfer();
                hasil.forEach(f => dt.items.add(f));
                input.files = dt.files;
            } catch (err) { /* peramban lama: kirim berkas asli */ }
        }

        if (otomatis) {
            info(input, 'Mengunggah ' + hasil.length + ' foto…');
            input.form.submit();
        } else {
            const n = jumlahDipilih(input.form);
            info(input, n ? '📎 ' + n + ' foto nota akan dilampirkan saat disimpan' : '');
        }
    });
})();
