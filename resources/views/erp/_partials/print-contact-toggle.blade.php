{{--
    Centang "Tampilkan kontak toko" di toolbar cetak faktur. Chat Shopee memblokir
    nota yang memuat nomor telepon / alamat web, jadi faktur pelanggan marketplace
    bawaannya TANPA baris kontak. Memasang kelas .no-contact di <body>; tombol
    "Simpan PDF" ikut membawa ?tanpa_kontak=1 supaya PDF-nya sama dengan layar.

    Param: $sembunyikanBawaan (bool)
--}}
<style>
    body.no-contact .doc-contacts { display: none; }
</style>
<label class="sig-toggle">
    <input type="checkbox" id="contact-toggle-input" @checked(! ($sembunyikanBawaan ?? false))>
    Tampilkan kontak toko
</label>

<script>
(function () {
    const input = document.getElementById('contact-toggle-input');
    if (!input) return;

    function terapkan() {
        document.body.classList.toggle('no-contact', !input.checked);

        const pdf = document.querySelector('.btn-pdf');
        if (pdf) {
            const url = new URL(pdf.href, window.location.origin);
            if (input.checked) url.searchParams.delete('tanpa_kontak');
            else url.searchParams.set('tanpa_kontak', '1');
            pdf.href = url.toString();
        }
    }
    input.addEventListener('change', terapkan);
    terapkan();
})();
</script>
