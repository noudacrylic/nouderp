{{-- JS shared untuk semua index purchasing: row-click navigation, live-search debounce, copy-to-clipboard. --}}
<script>
(function(){
    // Row click → navigate via data-href (skip if click target di dalam tombol/form/link)
    document.querySelectorAll('tr[data-href]').forEach(tr => {
        tr.addEventListener('click', (e) => {
            if (e.target.closest('button, a, form, input, select')) return;
            window.location = tr.dataset.href;
        });
    });

    // Live search: auto-submit form ketika user mengetik (debounced)
    document.querySelectorAll('.search-live').forEach(input => {
        let t;
        input.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(() => {
                if (input.form) input.form.submit();
            }, 600);
        });
    });

    // Submit penuh me-reload halaman & menghilangkan fokus. Kembalikan fokus + kursor
    // ke kotak pencarian yang sudah terisi, supaya user bisa lanjut mengetik tanpa klik ulang.
    const activeSearch = document.querySelector('.search-live');
    if (activeSearch && activeSearch.value) {
        activeSearch.focus();
        const len = activeSearch.value.length;
        try { activeSearch.setSelectionRange(len, len); } catch (_) {}
    }

    // Auto-submit untuk select / date filter (.filter-auto): submit langsung saat berubah
    document.querySelectorAll('.filter-auto').forEach(el => {
        el.addEventListener('change', () => {
            if (el.form) el.form.submit();
        });
    });

    // Copy button: salin teks ke clipboard + flash centang
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const text = btn.dataset.copy || '';
            const flash = () => {
                const orig = btn.innerHTML;
                btn.innerHTML = '<span class="text-[11px] font-bold text-green-600 leading-none">✓</span>';
                setTimeout(() => { btn.innerHTML = orig; }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(flash).catch(() => {
                    // fallback
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); flash(); } catch(_) {}
                    document.body.removeChild(ta);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); flash(); } catch(_) {}
                document.body.removeChild(ta);
            }
        });
    });
})();
</script>
