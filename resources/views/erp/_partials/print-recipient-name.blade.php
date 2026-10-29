{{--
    Kolom "Atas nama" di toolbar cetak faktur. Pesanan marketplace tercatat atas
    pelanggan "Shopee"/"Tokopedia"; pembeli yang minta faktur toko ditanya dulu
    "atas nama siapa", lalu namanya diketik di sini. Hanya mengganti nama di kertas
    (tidak menyimpan apa pun) — tombol "Simpan PDF" ikut membawa ?nama=... supaya
    PDF-nya sama dengan yang terlihat di layar.
--}}
<style>
    .recipient-name {
        margin-top: 4px; padding: 9px 12px;
        background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px;
        font-size: 12px; font-weight: 600; color: #334155;
    }
    .recipient-name input {
        width: 100%; box-sizing: border-box; margin-top: 6px; padding: 6px 8px;
        border: 1px solid #cbd5e1; border-radius: 4px;
        font-family: inherit; font-size: 12px; font-weight: 600; color: #334155;
        background: #fff;
    }
</style>
<label class="recipient-name">
    Atas nama
    <input type="text" id="recipient-name-input" maxlength="120"
           value="{{ request()->query('nama') }}" placeholder="Nama pembeli" autocomplete="off">
</label>

<script>
(function () {
    const input = document.getElementById('recipient-name-input');
    const names = Array.from(document.querySelectorAll('.recipient-block .name'));
    if (!input || !names.length) return;

    input.addEventListener('input', function () {
        const nama = this.value.trim();
        names.forEach((el) => { el.textContent = nama || el.dataset.defaultName || '-'; });

        const pdf = document.querySelector('.btn-pdf');
        if (pdf) {
            const url = new URL(pdf.href, window.location.origin);
            if (nama) url.searchParams.set('nama', nama);
            else url.searchParams.delete('nama');
            pdf.href = url.toString();
        }
    });
    if (input.value.trim()) input.dispatchEvent(new Event('input'));
})();
</script>
