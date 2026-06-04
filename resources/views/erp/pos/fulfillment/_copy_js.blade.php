<script>
// Klik elemen ber-class .js-copy untuk menyalin teksnya (data-copy / isi teks) ke clipboard.
(function () {
    if (window._copyWired) return;
    window._copyWired = true;

    document.addEventListener('click', function (e) {
        const el = e.target.closest('.js-copy');
        if (!el) return;
        const text = el.getAttribute('data-copy') || el.textContent.trim();
        if (!text) return;

        const done = () => {
            const old = el.textContent;
            el.classList.add('text-green-600');
            el.textContent = '✓ Tersalin';
            setTimeout(() => { el.textContent = old; el.classList.remove('text-green-600'); }, 900);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => {});
        } else {
            const ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (_) {}
            document.body.removeChild(ta);
        }
    });
})();
</script>
