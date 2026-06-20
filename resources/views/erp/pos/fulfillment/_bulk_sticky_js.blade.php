{{--
    Pin bar aksi massal ke atas viewport saat di-scroll. Param: $barId (id elemen bar).

    Kenapa tidak position:sticky? Container .content di layout punya overflow-y:auto TANPA
    tinggi tetap, sehingga ia jadi "sticky containing block" yang tak pernah scroll sendiri
    (yang scroll adalah window) → position:sticky mati untuk semua anaknya. JS ini meniru
    sticky lewat position:fixed, scoped ke bar ini saja (tanpa mengubah layout global).
--}}
<script>
(function () {
    var bar = document.getElementById(@json($barId));
    if (!bar) return;

    // Placeholder menahan ruang saat bar di-"fixed" agar konten di bawahnya tidak melompat.
    var ph = document.createElement('div');
    ph.setAttribute('aria-hidden', 'true');
    ph.style.display = 'none';
    bar.parentNode.insertBefore(ph, bar);

    var TOP = 8, pinned = false;

    function pin() {
        var r = bar.getBoundingClientRect(); // baca posisi natural (masih in-flow) utk left/width
        ph.style.display = 'block';
        ph.style.height = bar.offsetHeight + 'px';
        bar.style.position = 'fixed';
        bar.style.top = TOP + 'px';
        bar.style.left = r.left + 'px';
        bar.style.width = r.width + 'px';
        bar.style.marginTop = '0';
        bar.style.marginBottom = '0';
        bar.style.zIndex = '40';
        pinned = true;
    }
    function unpin() {
        ph.style.display = 'none';
        bar.style.position = bar.style.top = bar.style.left = bar.style.width = '';
        bar.style.marginTop = bar.style.marginBottom = bar.style.zIndex = '';
        pinned = false;
    }
    function sync() {
        if (bar.classList.contains('hidden')) { if (pinned) unpin(); return; }
        if (!pinned) {
            if (bar.getBoundingClientRect().top <= TOP) pin();
        } else if (ph.getBoundingClientRect().top >= TOP) {
            unpin(); // posisi natural sudah kembali terlihat → lepas
        } else {
            var pr = ph.getBoundingClientRect(); // jaga keselarasan horizontal saat resize
            bar.style.left = pr.left + 'px';
            bar.style.width = pr.width + 'px';
        }
    }

    window.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    var sc = document.querySelector('.content');
    if (sc) sc.addEventListener('scroll', sync, { passive: true });
    // Re-evaluasi saat memilih/menghapus pilihan menampilkan/menyembunyikan bar.
    document.addEventListener('change', function () { setTimeout(sync, 0); });
    document.addEventListener('click', function () { setTimeout(sync, 0); });
    sync();
})();
</script>
