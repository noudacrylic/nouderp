{{--
    Tombol "Terapkan ke marketplace" (hanya ada di kanal Website).

    Dua hal yang dikerjakan di sini, dan keduanya penting:

    1. Harga yang dikirim diambil dari kolom yang SEDANG DIKETIK, bukan dari yang tersimpan.
       Tanpa ini orang mengetik harga baru, menekan tombol ini, lalu seluruh marketplace
       disamakan dengan harga lama — tanpa satu pun tanda bahwa yang berlaku bukan angka
       yang barusan diketik.

    2. Konfirmasi yang menyebut kanalnya satu per satu. Tombol ini menimpa harga khusus
       tiap marketplace sekaligus mengirimnya ke toko sungguhan; sekali tekan, harga di
       Shopee & Tokopedia benar-benar berubah. Itu bukan aksi yang boleh terjadi karena
       kursor meleset.
--}}
<script>
(function () {
    const tabel = document.getElementById('tabel-harga');
    if (!tabel) return;

    const KANAL = @json($kanalPasar);

    tabel.addEventListener('submit', function (e) {
        const form = e.target.closest('.js-terapkan');
        if (!form) return;

        const baris = form.closest('tr');
        const input = baris.querySelector('.js-harga');
        // Input rupiah diketik dengan titik ribuan; ambil digitnya saja.
        const harga = parseInt(String(input ? input.value : '').replace(/[^\d]/g, ''), 10) || 0;

        if (!harga) {
            e.preventDefault();
            alert('Isi harga dasarnya dulu — angka inilah yang akan disalin ke semua marketplace.');
            if (input) input.focus();
            return;
        }

        const nama = (baris.querySelector('td .font-semibold') || {}).textContent || 'produk ini';
        const rp   = 'Rp' + harga.toLocaleString('id-ID');

        if (!confirm(
            nama.trim() + '\n\n'
            + 'Harga dasar ' + rp + ' akan disimpan, lalu diberlakukan di ' + KANAL.join(', ') + ' '
            + 'dan langsung dikirim ke tokonya lewat Jubelio.\n\n'
            + 'Harga khusus kanal yang berbeda akan ditimpa. Lanjutkan?'
        )) {
            e.preventDefault();
            return;
        }

        form.querySelector('input[name="price"]').value = harga;
    });
})();
</script>
