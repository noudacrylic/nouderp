{{--
    Hitung ulang seketika saat harga/qty/persen afiliasi diketik — dipakai ketiga sub-tab.

    Rumusnya harus sama persis dengan PricingMath di sisi server, karena angka yang muncul
    sebelum disimpan tidak boleh berbeda dengan yang muncul sesudahnya. Kalau salah satunya
    diubah, ubah keduanya. Aturan warnanya pun mengikuti _persen.blade.php.

    Kontrak DOM: tabel membawa data-percent & data-fixed; tiap baris membawa data-hpp;
    input .js-harga / .js-grosir / .js-qty / .js-afiliasi; sel .js-potongan,
    .js-potongan-persen, .js-untung, .js-total, serta angka .js-margin & .js-markup yang
    masing-masing berada di dalam pembungkus warnanya sendiri.
--}}
<script>
(function () {
    const tabel = document.getElementById('tabel-harga');
    if (!tabel) return;

    const PERSEN = parseFloat(tabel.dataset.percent || '0');
    const TETAP  = parseFloat(tabel.dataset.fixed || '0');
    const WARNA  = ['text-red-600', 'text-amber-600', 'text-emerald-600', 'text-indigo-600', 'text-slate-300'];

    const rupiah = (v) => v === null ? '—' : 'Rp' + Math.round(v).toLocaleString('id-ID');
    const persen = (v) => v === null ? '—' : v.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    // Input rupiah diketik dengan titik ribuan; ambil digitnya saja.
    const angka  = (el) => el ? parseFloat(String(el.value).replace(/[^\d]/g, '')) || 0 : 0;

    function tulis(baris, kelas, teks, merah) {
        const el = baris.querySelector(kelas);
        if (!el) return;
        el.textContent = teks;
        if (merah !== undefined) {
            el.classList.toggle('text-red-600', merah);
            el.classList.toggle('text-slate-800', !merah);
        }
    }

    /** Margin & markup: angkanya diganti, warnanya menempel di pembungkusnya. */
    function tulisPersen(baris, kelas, nilai, jenis) {
        const el = baris.querySelector(kelas);
        if (!el) return;
        el.textContent = persen(nilai);

        const wrap = el.parentElement;
        WARNA.forEach((c) => wrap.classList.remove(c));

        if (nilai === null)                        wrap.classList.add('text-slate-300');
        else if (nilai < 0)                        wrap.classList.add('text-red-600');
        else if (jenis === 'margin' && nilai < 20) wrap.classList.add('text-amber-600');
        else                                       wrap.classList.add(jenis === 'margin' ? 'text-emerald-600' : 'text-indigo-600');
    }

    function hitung(baris) {
        const hpp     = baris.dataset.hpp === '' ? null : parseFloat(baris.dataset.hpp);
        const inputRp = baris.querySelector('.js-grosir') || baris.querySelector('.js-harga');
        const qtyEl   = baris.querySelector('.js-qty');
        const afiliEl = baris.querySelector('.js-afiliasi');

        const qty    = qtyEl ? Math.max(1, parseInt(qtyEl.value || '1', 10) || 1) : 1;
        const tambah = afiliEl ? (parseFloat(String(afiliEl.value).replace(',', '.')) || 0) : 0;
        const harga  = angka(inputRp);
        const omzet  = harga * qty;

        if (!harga) {
            tulis(baris, '.js-potongan', '—');
            tulis(baris, '.js-untung', '—', false);
            ['.js-potongan-persen', '.js-total'].forEach((k) => tulis(baris, k, ''));
            tulisPersen(baris, '.js-margin', null, 'margin');
            tulisPersen(baris, '.js-markup', null, 'markup');
            return;
        }

        const potongan = omzet * ((PERSEN + tambah) / 100) + TETAP;
        const untung   = hpp === null ? null : omzet - potongan - hpp * qty;

        tulis(baris, '.js-total', rupiah(omzet));
        tulis(baris, '.js-potongan', rupiah(potongan));
        tulis(baris, '.js-potongan-persen', persen(potongan / omzet * 100) + ' efektif');

        if (untung === null) {
            tulis(baris, '.js-untung', '—', false);
            tulisPersen(baris, '.js-margin', null, 'margin');
            tulisPersen(baris, '.js-markup', null, 'markup');
            return;
        }

        tulis(baris, '.js-untung', rupiah(untung), untung < 0);
        tulisPersen(baris, '.js-margin', untung / omzet * 100, 'margin');
        tulisPersen(baris, '.js-markup', hpp > 0 ? untung / (hpp * qty) * 100 : null, 'markup');
    }

    tabel.addEventListener('input', (e) => {
        const baris = e.target.closest('tr');
        if (baris && e.target.matches('.js-harga, .js-grosir, .js-qty, .js-afiliasi')) hitung(baris);
    });

    // Tombol "pakai" pada kolom usulan: isikan angkanya ke kolom harga lalu hitung ulang.
    tabel.addEventListener('click', (e) => {
        const tombol = e.target.closest('.js-pakai');
        if (!tombol) return;
        const baris = tombol.closest('tr');
        const input = baris.querySelector('.js-grosir') || baris.querySelector('.js-harga');
        if (!input) return;
        input.value = parseInt(tombol.dataset.harga, 10).toLocaleString('id-ID');
        input.focus();
        hitung(baris);
    });
})();
</script>
