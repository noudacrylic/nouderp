{{-- Setelan Trix bersama — WAJIB dimuat SEBELUM trix.umd.min.js.

     Halaman terbitnya sudah memakai <h1> untuk judul (tutorial & artikel),
     jadi heading di dalam ISI harus setingkat di bawahnya. Bawaan Trix
     menerbitkan <h1>, yang berarti satu halaman punya banyak <h1> — struktur
     yang justru melemahkan kolom yang memang dibuat untuk mesin pencari.

     URUTANNYA MENENTUKAN, dan inilah yang dulu membuat heading "balik lagi
     jadi teks biasa setiap kali disimpan":

     Trix menghafal daftar nama tag blok pada PARSE PERTAMA (`blockTagNames`
     di dalam pustakanya, dihitung sekali lalu disimpan). Elemen <trix-editor>
     ikut hidup dan langsung mem-parse isi tersimpannya begitu berkas pustaka
     dijalankan — sebelum <script> berikutnya sempat jalan. Bila setelan ini
     baru dipasang SESUDAH itu:

       - daftar tag blok terlanjur berisi 'h1', selamanya untuk halaman itu;
       - naskah tersimpan yang berisi <h2> dibaca sebagai paragraf biasa —
         tulisannya utuh, statusnya sebagai heading lenyap tanpa pesan apa pun;
       - tombol Heading tetap MENERBITKAN <h2> (penulisan memang membaca
         setelan ini), jadi sekali simpan terlihat benar, lalu hilang lagi
         begitu halaman dibuka ulang.

     Karena itu setelan dipasang lewat `trix-before-initialize` — peristiwa
     yang Trix picu tepat sebelum editor mem-parse isinya. Mendaftarkan
     penyimaknya tidak butuh Trix, jadi berkas ini aman (dan memang harus)
     dimuat lebih dulu. --}}
<script>
    (function () {
        function terapkan() {
            if (window.Trix) Trix.config.blockAttributes.heading1.tagName = 'h2';
        }

        // Jalur normal: dipasang sebelum editor mana pun mem-parse isinya.
        document.addEventListener('trix-before-initialize', terapkan);

        // Jaring pengaman bila berkas ini terlanjur dimuat setelah pustakanya:
        // tombol Heading tetap menerbitkan <h2>, walau naskah yang sudah
        // terlanjur di-parse pada halaman itu tidak bisa diselamatkan lagi.
        terapkan();
    })();
</script>
