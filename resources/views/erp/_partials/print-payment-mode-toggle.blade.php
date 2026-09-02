{{--
    Pilihan info pembayaran yang ikut tercetak: link+QR, rekening transfer, keduanya,
    atau tanpa info. Perusahaan besar biasanya minta nomor rekening, jadi pilihannya
    per cetakan — bukan mengubah setting Midtrans.

    Bekerja atas blok yang dirender print-payment-block: cukup menukar display, tanpa
    reload. Tombol "Simpan PDF" ikut diperbarui (?bayar=...) supaya PDF-nya sama dengan
    yang terlihat di layar. Pilihan yang datanya tidak ada otomatis tidak ditawarkan.
--}}
<style>
    .pay-mode {
        margin-top: 4px; padding: 9px 12px;
        background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px;
        font-size: 12px; font-weight: 600; color: #334155;
    }
    .pay-mode select {
        width: 100%; margin-top: 6px; padding: 6px 8px;
        border: 1px solid #cbd5e1; border-radius: 4px;
        font-family: inherit; font-size: 12px; font-weight: 600; color: #334155;
        background: #fff; cursor: pointer;
    }
</style>
<div class="pay-mode" id="pay-mode-box" style="display:none;">
    Info pembayaran
    <select id="pay-mode-select"></select>
</div>

<script>
(function () {
    const box    = document.getElementById('pay-mode-box');
    const select = document.getElementById('pay-mode-select');
    const links  = Array.from(document.querySelectorAll('.pay-info--link'));
    const banks  = Array.from(document.querySelectorAll('.pay-info--bank'));

    // Tidak ada yang bisa ditukar (dokumen lunas / rekening belum diisi) → sembunyikan.
    if (!links.length && !banks.length) return;

    const options = [];
    if (links.length) options.push(['link', 'Link & QR pembayaran']);
    if (banks.length) options.push(['rekening', 'Transfer rekening']);
    if (links.length && banks.length) options.push(['dua', 'Link & rekening']);
    options.push(['tanpa', 'Tanpa info pembayaran']);

    if (options.length < 2) return;

    const visible = (els) => els.some((el) => el.style.display !== 'none');
    const current = visible(links) && visible(banks) ? 'dua'
                  : (visible(links) ? 'link' : (visible(banks) ? 'rekening' : 'tanpa'));

    options.forEach(([value, label]) => {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        opt.selected = value === current;
        select.appendChild(opt);
    });
    box.style.display = '';

    select.addEventListener('change', function () {
        const mode = this.value;
        links.forEach((el) => { el.style.display = (mode === 'link' || mode === 'dua') ? 'flex' : 'none'; });
        banks.forEach((el) => { el.style.display = (mode === 'rekening' || mode === 'dua') ? 'block' : 'none'; });

        // PDF dirender ulang di server — bawa pilihannya lewat query.
        const pdf = document.querySelector('.btn-pdf');
        if (pdf) {
            const url = new URL(pdf.href, window.location.origin);
            url.searchParams.set('bayar', mode);
            pdf.href = url.toString();
        }
    });
})();
</script>
