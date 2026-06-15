{{-- JS shared untuk semua index: row-click navigation, live-search, copy-to-clipboard.

     Mode pencarian:
       • Default (semua index lama): ketik → debounce → submit penuh (reload halaman).
       • AJAX in-place (opt-in): beri atribut data-live-results="#selector" pada <form>
         filter, dan bungkus tabel+pagination dalam container ber-id sama. Pencarian &
         filter akan mengganti isi container tanpa reload → fokus ketik tak pernah hilang.

     Row-click & copy memakai event delegation supaya tetap jalan setelah konten di-swap. --}}
<script>
(function(){
    const liveForm   = document.querySelector('form[data-live-results]');
    const resultsSel = liveForm ? liveForm.getAttribute('data-live-results') : null;
    const resultsBox = resultsSel ? document.querySelector(resultsSel) : null;
    const ajaxMode   = !!resultsBox;

    // ── Row click → navigate via data-href (delegated; lewati klik di kontrol interaktif) ──
    document.addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-href]');
        if (!tr) return;
        if (e.target.closest('button, a, form, input, select, label, textarea')) return;
        window.location = tr.dataset.href;
    });

    // ── Copy button: salin ke clipboard + flash centang (delegated) ──
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.copy-btn');
        if (!btn) return;
        e.stopPropagation();
        const text  = btn.dataset.copy || '';
        const flash = () => {
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="text-[11px] font-bold text-green-600 leading-none">✓</span>';
            setTimeout(() => { btn.innerHTML = orig; }, 1200);
        };
        const fallback = () => {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); flash(); } catch(_) {}
            document.body.removeChild(ta);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(flash).catch(fallback);
        } else {
            fallback();
        }
    });

    // ── AJAX in-place: ambil halaman ter-filter, ganti isi container saja ──
    let ajaxToken = 0;
    function runLive() {
        if (!ajaxMode) return;
        const params = new URLSearchParams(new FormData(liveForm));
        const base   = liveForm.getAttribute('action') || window.location.pathname;
        const url    = base + (params.toString() ? ('?' + params.toString()) : '');
        const token  = ++ajaxToken;

        resultsBox.style.opacity = '0.5';
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.text())
            .then(html => {
                if (token !== ajaxToken) return; // respons usang (user sudah ketik lagi) → abaikan
                const doc   = new DOMParser().parseFromString(html, 'text/html');
                const fresh = doc.querySelector(resultsSel);
                if (fresh) resultsBox.innerHTML = fresh.innerHTML;
                try { window.history.replaceState(null, '', url); } catch(_) {}
                if (window.Alpine && window.Alpine.initTree) {
                    try { window.Alpine.initTree(resultsBox); } catch(_) {}
                }
                document.dispatchEvent(new CustomEvent('list:updated', { detail: { container: resultsBox } }));
            })
            .catch(() => {})
            .finally(() => { if (token === ajaxToken) resultsBox.style.opacity = ''; });
    }

    // ── Live search: ketik → debounce ──
    document.querySelectorAll('.search-live').forEach(input => {
        let t;
        input.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(() => {
                if (ajaxMode) runLive();
                else if (input.form) input.form.submit();
            }, ajaxMode ? 350 : 600);
        });
    });

    // ── Filter select/tanggal (.filter-auto): terapkan saat berubah ──
    document.querySelectorAll('.filter-auto').forEach(el => {
        el.addEventListener('change', () => {
            if (ajaxMode) runLive();
            else if (el.form) el.form.submit();
        });
    });

    // ── Mode AJAX: cegah submit penuh (mis. tekan Enter di kotak cari) ──
    if (ajaxMode) {
        liveForm.addEventListener('submit', (e) => { e.preventDefault(); runLive(); });
    }

    // ── Mode reload: kembalikan fokus + kursor ke kotak cari yang sudah terisi ──
    if (!ajaxMode) {
        const activeSearch = document.querySelector('.search-live');
        if (activeSearch && activeSearch.value) {
            activeSearch.focus();
            const len = activeSearch.value.length;
            try { activeSearch.setSelectionRange(len, len); } catch (_) {}
        }
    }
})();
</script>
