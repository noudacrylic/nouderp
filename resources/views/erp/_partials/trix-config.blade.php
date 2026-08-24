{{-- Setelan Trix bersama — muat SETELAH trix.umd.min.js.

     Halaman terbitnya sudah memakai <h1> untuk judul (tutorial & artikel),
     jadi heading di dalam ISI harus setingkat di bawahnya. Bawaan Trix
     menerbitkan <h1>, yang berarti satu halaman punya banyak <h1> — struktur
     yang justru melemahkan kolom yang memang dibuat untuk mesin pencari. --}}
<script>
    Trix.config.blockAttributes.heading1.tagName = 'h2';
</script>
