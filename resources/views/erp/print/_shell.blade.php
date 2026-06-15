{{--
    Reusable print shell: 3-kolom (thumbnail | preview kertas | toolbar)
    seperti Accurate / Chrome PDF viewer.

    Required vars:
      $printTitle - title tab
      $indexUrl   - URL keluar (kembali ke index)
    Optional vars:
      $pdfUrl     - URL download PDF (kalau ada, tombol "Simpan PDF" muncul)
      $pdfMode    - true saat di-render untuk Browsershot (skip Google Fonts)

    Child harus emit `<article class="paper">...` per halaman di section
    `papers`. Thumbnail di-generate otomatis dari .paper element.
    Optional: `<article class="paper" data-label="Lampiran">` untuk label kustom.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $printTitle ?? 'Print' }}</title>
    @include('layouts.partials._favicon')
    <style>
        @if(!($pdfMode ?? false))
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;900&display=swap');
        @endif

        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
            margin: 0; padding: 0;
            font-size: 12px;
            line-height: 1.5;
            background: #525659;
        }

        /* === 3-column shell (screen) === */
        .shell {
            display: grid;
            grid-template-columns: 110px 1fr 200px;
            height: 100vh;
            overflow: hidden;
        }
        .thumb-bar {
            background: #2d2d2d;
            padding: 16px 0;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }
        .thumb { width: 78px; cursor: pointer; text-align: center; }
        .thumb .thumb-page {
            width: 78px; height: 110px;
            background: #fff;
            border: 2px solid transparent;
            border-radius: 2px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4);
            display: flex; align-items: center; justify-content: center;
            color: #cbd5e1; font-size: 22px; font-weight: 700;
        }
        .thumb.active .thumb-page { border-color: #3b82f6; }
        .thumb .thumb-label { color: #cbd5e1; font-size: 11px; margin-top: 4px; }
        .thumb.active .thumb-label { color: #fff; }
        .thumb:hover .thumb-page { border-color: #64748b; }

        .canvas {
            overflow-y: auto;
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        .paper {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            box-shadow: 0 4px 16px rgba(0,0,0,0.35);
            padding: 12mm;
            color: #1e293b;
        }

        .toolbar {
            background: #fff;
            border-left: 1px solid #cbd5e1;
            padding: 16px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toolbar button, .toolbar a {
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            border: 0;
            font-family: inherit;
            font-weight: 600;
            text-decoration: none;
            display: flex; align-items: center; justify-content: center;
            gap: 6px;
            width: 100%;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
            transition: background 0.15s;
        }
        .toolbar .btn-print { background: #1d4ed8; color: #fff; }
        .toolbar .btn-print:hover { background: #1e40af; }
        .toolbar .btn-pdf { background: #2563eb; color: #fff; }
        .toolbar .btn-pdf:hover { background: #1d4ed8; }
        .toolbar .btn-exit { background: #ec4899; color: #fff; }
        .toolbar .btn-exit:hover { background: #db2777; }

        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; height: auto; min-height: 100vh; }
            .thumb-bar { display: none; }
            .canvas { padding: 12px; padding-bottom: 90px; }
            .paper { width: 100%; min-height: 0; }
            .toolbar {
                position: fixed; left: 0; right: 0; bottom: 0;
                flex-direction: row;
                border-left: 0;
                border-top: 1px solid #cbd5e1;
                padding: 10px;
            }
            .toolbar button, .toolbar a { flex: 1; }
        }

        @media print {
            html, body { height: auto; background: #fff; }
            .shell { display: block; height: auto; overflow: visible; }
            .thumb-bar, .toolbar { display: none !important; }
            .canvas { padding: 0; display: block; }
            .paper {
                width: auto; min-height: 0;
                box-shadow: none;
                padding: 12mm;
            }
            .paper + .paper { page-break-before: always; break-before: page; }
        }
        @page { margin: 0; size: {{ $pageSize ?? 'A4' }}; }
    </style>
</head>
<body>

<div class="shell">

<aside class="thumb-bar" id="thumb-bar"></aside>

<main class="canvas" id="canvas">
    @yield('papers')
</main>

<aside class="toolbar">
    <button type="button" class="btn-print" onclick="triggerPrint()">🖨️ Cetak</button>
    @if(!empty($pdfUrl))
        <a class="btn-pdf" href="{{ $pdfUrl }}">💾 Simpan PDF</a>
    @endif
    <a class="btn-exit" href="{{ $indexUrl }}">⨯ Keluar</a>
</aside>

</div>

<script>
    let _redirectAfterPrint = false;
    const _indexUrl = @json($indexUrl);

    function triggerPrint() {
        _redirectAfterPrint = true;
        window.print();
    }
    window.addEventListener('afterprint', function () {
        if (_redirectAfterPrint) {
            window.location.href = _indexUrl;
        }
    });

    (function () {
        const thumbBar = document.getElementById('thumb-bar');
        const canvas   = document.getElementById('canvas');
        const papers   = document.querySelectorAll('.paper');

        papers.forEach((p, i) => {
            const idx = i + 1;
            if (!p.id) p.id = 'paper-' + idx;
            const label = p.dataset.label || 'Halaman';
            const t = document.createElement('div');
            t.className = 'thumb' + (i === 0 ? ' active' : '');
            t.dataset.target = p.id;
            t.innerHTML = '<div class="thumb-page">' + idx + '</div>'
                        + '<div class="thumb-label">' + label + '</div>';
            thumbBar.appendChild(t);
        });

        document.querySelectorAll('.thumb').forEach((t) => {
            t.addEventListener('click', () => {
                const target = document.getElementById(t.dataset.target);
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.querySelectorAll('.thumb').forEach((x) => x.classList.remove('active'));
                t.classList.add('active');
            });
        });

        if (canvas && 'IntersectionObserver' in window) {
            const obs = new IntersectionObserver((entries) => {
                entries.forEach((e) => {
                    if (!e.isIntersecting) return;
                    const id = e.target.id;
                    document.querySelectorAll('.thumb').forEach((x) => {
                        x.classList.toggle('active', x.dataset.target === id);
                    });
                });
            }, { root: canvas, threshold: 0.4 });
            papers.forEach((p) => obs.observe(p));
        }
    })();
</script>

</body>
</html>
