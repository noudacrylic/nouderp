<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Penawaran {{ $quotation->quotation_number }}</title>
    <style>
        @if(!($pdfMode ?? false))
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;900&display=swap');
        @endif

        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.5;
            background: #525659;
        }

        /* ===== 3-column shell (screen only) ===== */
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
        .thumb {
            width: 78px;
            cursor: pointer;
            text-align: center;
        }
        .thumb .thumb-page {
            width: 78px;
            height: 110px;
            background: #fff;
            border: 2px solid transparent;
            border-radius: 2px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #cbd5e1;
            font-size: 22px;
            font-weight: 700;
        }
        .thumb.active .thumb-page { border-color: #3b82f6; }
        .thumb .thumb-label {
            color: #cbd5e1;
            font-size: 11px;
            margin-top: 4px;
        }
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

        .doc-head { width: 100%; margin: 6px 0 14px 0; border-collapse: collapse; }
        .doc-head td { vertical-align: top; padding: 0; }
        .doc-head .recipient .label { color: #475569; font-weight: 500; }
        .doc-head .recipient .name { font-weight: 700; font-size: 13px; }
        .doc-head .recipient .addr { color: #475569; white-space: pre-line; }
        .doc-head table.meta { border-collapse: collapse; display: inline-table; text-align: left; }
        .doc-head table.meta td { padding: 2px 0; vertical-align: top; font-size: 12px; }
        .doc-head table.meta td.label { width: 80px; color: #475569; }
        .doc-head table.meta td.colon { width: 10px; color: #475569; }

        .opening { margin: 24px 0 12px 0; text-align: justify; }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 0 0;
            font-size: 12px;
        }
        table.items th, table.items td {
            border: 1px solid #94a3b8;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
        }
        table.items th {
            background: #f1f5f9;
            font-weight: 700;
            color: #334155;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.items td.num { text-align: right; }
        table.items td.center { text-align: center; }
        table.items td .sku-prefix { font-family: monospace; font-weight: 700; font-size: 11px; }
        table.items td.disc { }

        .summary-wrap { display: flex; gap: 16px; margin-top: 14px; }
        .summary-wrap .notes-box {
            flex: 1;
            padding: 4px 0;
        }
        .summary-wrap .notes-box .h { font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .summary-wrap .notes-box .body { white-space: pre-wrap; color: #334155; min-height: 40px; }

        .summary-wrap .totals-box {
            width: 280px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 12px;
            background: #fff;
        }
        .summary-wrap .totals-box .row {
            display: flex; justify-content: space-between; padding: 3px 0;
            color: #475569;
        }
        .summary-wrap .totals-box .row .neg { color: #ef4444; }
        .summary-wrap .totals-box hr { border: 0; border-top: 1px solid #e5e7eb; margin: 6px 0; }
        .summary-wrap .totals-box .grand {
            display: flex; justify-content: space-between; padding: 6px 0;
            font-weight: 800; font-size: 14px; color: #0f172a;
        }

        .payment-info { margin-top: 18px; font-size: 12px; }
        .payment-info .head { font-weight: 700; margin-bottom: 4px; color: #0f172a; }
        .payment-info table { border-collapse: collapse; }
        .payment-info table td { padding: 1px 0; vertical-align: top; }
        .payment-info table td.k { width: 110px; color: #475569; }
        .payment-info table td.s { width: 12px; color: #475569; }

        .closing { margin-top: 18px; }

        .signature-block { margin-top: 12px; }
        .signature-block .sig-img { height: 70px; max-width: 220px; display: block; margin: 4px 0; }
        .signature-block .sig-name { font-weight: 700; text-decoration: underline; }
        .signature-block .sig-title { color: #475569; font-size: 11px; }

        /* Toggle sertakan tanda tangan / nama (kelas dipasang di <body>). Disembunyikan
           dengan visibility agar ruangnya tetap untuk tanda tangan/nama manual, tanpa garis. */
        body.no-sig .signature-block .sig-img { visibility: hidden; }
        body.no-name .signature-block .sig-name { visibility: hidden; }
        .sig-toggle {
            display: flex; align-items: center; gap: 8px;
            margin-top: 4px; padding: 9px 12px;
            background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px;
            font-size: 12px; font-weight: 600; color: #334155; cursor: pointer;
            user-select: none;
        }
        .sig-toggle input { width: 16px; height: 16px; cursor: pointer; margin: 0; }

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
            display: flex;
            align-items: center;
            justify-content: center;
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
            .shell {
                grid-template-columns: 1fr;
                height: auto;
                min-height: 100vh;
            }
            .thumb-bar { display: none; }
            .canvas { padding: 12px; padding-bottom: 90px; }
            .paper { width: 100%; min-height: 0; }
            .toolbar {
                position: fixed;
                left: 0; right: 0; bottom: 0;
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
                width: auto;
                min-height: 0;
                box-shadow: none;
                padding: 12mm;
            }
            .paper + .paper { page-break-before: always; break-before: page; }
        }
        @page { margin: 0; size: A4; }

        /* === Attachment pages === */
        .att-page .att-page-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #cbd5e1;
        }
        /* Tiap lampiran: gambar di kiri, judul+deskripsi di kanan.
           Lebar kolom teks berubah sesuai panjang deskripsi (auto via class server-side):
           - .no-desc   → kolom teks paling sempit, gambar paling lebar
           - .text-short→ kolom teks menengah
           - default    → kolom teks normal */
        .att-block {
            display: flex;
            align-items: stretch;
            gap: 8mm;
            margin-bottom: 12mm;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .att-block:last-child { margin-bottom: 0; }
        .att-block .att-img-wrap {
            flex: 1 1 0;
            min-width: 0;
            height: 115mm;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .att-block .att-img-wrap img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }
        .att-block .att-text-wrap {
            width: 76mm;
            flex-shrink: 0;
            height: 115mm;
            max-height: 115mm;
            overflow: hidden;
        }
        .att-block.text-short .att-text-wrap { width: 58mm; }
        .att-block.no-desc .att-text-wrap { width: 46mm; }
        .att-block .att-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        .att-block .att-desc {
            font-size: 11.5px;
            color: #475569;
            white-space: pre-line;
            line-height: 1.45;
            word-wrap: break-word;
        }
    </style>
</head>
<body>

@php
    // Format tanggal Indonesia (independen dari Carbon locale).
    $bulan = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $d = $quotation->quotation_date instanceof \Carbon\Carbon
        ? $quotation->quotation_date
        : \Carbon\Carbon::parse($quotation->quotation_date);
    $tglFormatted = $d->day . ' ' . $bulan[(int)$d->month] . ' ' . $d->year;

    // Hitung breakdown summary — sama dgn show view.
    $subtotal = $quotation->items->sum(fn($it) => (float) $it->subtotal);
    $discountGlobal = (float) ($quotation->discount_global ?? 0);
    $dpp = max(0, $subtotal - $discountGlobal);
    $ppn = $dpp * (((float) ($quotation->ppn_percent ?? 0)) / 100);
    $shipping = (float) ($quotation->shipping_charge ?? 0);
    $shipGross = (float) ($quotation->shipping_gross ?? 0);
    $shipDiscount = max(0, $shipGross - $shipping);
    $expense = (float) ($quotation->service_charge ?? 0) + (float) ($quotation->other_expense ?? 0);
    $hasNotes = !empty(trim($quotation->notes ?? ''));

    $attachmentChunks = $quotation->attachments->chunk(2);
    $totalPages = 1 + $attachmentChunks->count();
@endphp

<div class="shell">

<aside class="thumb-bar">
    <div class="thumb active" data-target="paper-1">
        <div class="thumb-page">1</div>
        <div class="thumb-label">Halaman</div>
    </div>
    @foreach($attachmentChunks as $i => $g)
        <div class="thumb" data-target="paper-{{ $i + 2 }}">
            <div class="thumb-page">{{ $i + 2 }}</div>
            <div class="thumb-label">Lampiran</div>
        </div>
    @endforeach
</aside>

<main class="canvas" id="canvas">

<article class="paper" id="paper-1">

@include('erp._partials.print-header', ['profile' => $profile])

<table class="doc-head">
    <tr>
        <td style="width:62%; padding-right:18px;">
            <div class="recipient">
                <div class="label">Kepada Yth.</div>
                <div class="name">{{ $quotation->customer->name ?? '-' }}</div>
                @if(!empty($quotation->customer?->address))
                    <div class="addr">{{ $quotation->customer->address }}</div>
                @endif
            </div>
        </td>
        <td style="width:38%; text-align:right;">
            <table class="meta">
                <tr>
                    <td class="label">Perihal</td><td class="colon">:</td>
                    <td>{{ $quotation->perihal ?: 'Penawaran' }}</td>
                </tr>
                <tr>
                    <td class="label">Nomor</td><td class="colon">:</td>
                    <td>{{ $quotation->quotation_number }}</td>
                </tr>
                <tr>
                    <td class="label">Tanggal</td><td class="colon">:</td>
                    <td>{{ $tglFormatted }}</td>
                </tr>
                @if($quotation->lampiran)
                    <tr>
                        <td class="label">Lampiran</td><td class="colon">:</td>
                        <td>{{ $quotation->lampiran }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@php
    $openingText = $quotation->opening_text ?: $profile->quotation_opening_text;
    if ($openingText) {
        $openingText = str_replace('{name}', $profile->name ?? '', $openingText);
    }
@endphp
@if($openingText)
    <div class="opening">{{ $openingText }}</div>
@endif

<table class="items">
    <thead>
        <tr>
            <th>Nama Produk</th>
            <th style="width:42px;" class="num">Qty</th>
            <th style="width:48px;" class="center">Unit</th>
            <th style="width:85px;" class="num">Harga</th>
            <th style="width:80px;" class="num">Diskon</th>
            <th style="width:100px;" class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse($quotation->items as $it)
            @php $sku = $it->product->sku ?? null; @endphp
            <tr>
                <td>
                    @if($sku)<span class="sku-prefix">{{ $sku }}</span> &mdash; @endif{{ $it->description ?: ($it->product->name ?? '-') }}
                </td>
                <td class="num">{{ rtrim(rtrim(number_format((float)$it->qty, 2, ',', '.'), '0'), ',') }}</td>
                <td class="center">{{ $it->unit_name ?? 'pcs' }}</td>
                <td class="num">{{ number_format((float)$it->unit_price, 0, ',', '.') }}</td>
                <td class="num disc">{{ ($it->line_discount ?? 0) > 0 ? '- ' . number_format((float)$it->line_discount, 0, ',', '.') : '0' }}</td>
                <td class="num" style="font-weight:700;">{{ number_format((float)$it->line_total, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center" style="color:#94a3b8; padding:16px;">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

<div class="summary-wrap">
    <div class="notes-box">
        <div class="h">Keterangan</div>
        <div class="body">{{ $hasNotes ? $quotation->notes : '—' }}</div>
    </div>
    <div class="totals-box">
        <div class="row"><span>Subtotal</span><span>{{ number_format($subtotal, 0, ',', '.') }}</span></div>
        @if($discountGlobal > 0)
            <div class="row"><span>Diskon Global</span><span class="neg">- {{ number_format($discountGlobal, 0, ',', '.') }}</span></div>
        @endif
        @if($ppn > 0)
            <div class="row"><span>PPN</span><span>{{ number_format($ppn, 0, ',', '.') }}</span></div>
        @endif
        @if($shipping > 0 || $shipDiscount > 0)
            <div class="row"><span>Ongkos Kirim</span><span>{{ number_format($shipDiscount > 0 ? $shipGross : $shipping, 0, ',', '.') }}</span></div>
        @endif
        @if($shipDiscount > 0)
            <div class="row"><span>Diskon Ongkir</span><span class="neg">- {{ number_format($shipDiscount, 0, ',', '.') }}</span></div>
        @endif
        @if($expense > 0)
            <div class="row"><span>Biaya Lain</span><span>{{ number_format($expense, 0, ',', '.') }}</span></div>
        @endif
        <hr>
        <div class="grand"><span>Grand Total</span><span>Rp {{ number_format((float)$quotation->grand_total, 0, ',', '.') }}</span></div>
    </div>
</div>

@php
    $paymentTerms = $quotation->payment_terms ?: $profile->quotation_payment_terms;
    // Rekening hanya tampil bila penawaran ini di-set "tampilkan rekening".
    $showBank = $quotation->show_bank_account && ($profile->bank_name || $profile->bank_account_number);
@endphp
@if($paymentTerms || $showBank)
    <div class="payment-info">
        <div class="head">Cara Pembayaran</div>
        @if($paymentTerms)
            <div style="margin-bottom:6px;">{{ $paymentTerms }}</div>
        @endif
        @if($showBank)
            <table>
                <tr><td class="k">Nama Bank</td><td class="s">:</td><td>{{ $profile->bank_name ?: '-' }}</td></tr>
                <tr><td class="k">No. Rekening</td><td class="s">:</td><td>{{ $profile->bank_account_number ?: '-' }}</td></tr>
                <tr><td class="k">Atas Nama</td><td class="s">:</td><td>{{ $profile->bank_account_holder ?: '-' }}</td></tr>
            </table>
        @endif
    </div>
@endif

<div class="closing">
    <div>Demikian surat penawaran ini kami sampaikan. Atas perhatian dan kerja samanya, kami ucapkan terima kasih.</div>
    <div style="margin-top:14px;">Hormat kami,</div>
    <div class="signature-block">
        @if($profile->signature_data_uri)
            <img src="{{ $profile->signature_data_uri }}" alt="Tanda Tangan" class="sig-img">
        @else
            <div style="height:70px;"></div>
        @endif
        <div class="sig-name">{{ $profile->signer_name ?: '—' }}</div>
        @if($profile->signer_title)
            <div class="sig-title">{{ $profile->signer_title }}</div>
        @endif
    </div>
</div>

</article>{{-- /paper-1 --}}

@foreach($attachmentChunks as $pageIdx => $pageGroup)
    <article class="paper att-page" id="paper-{{ $pageIdx + 2 }}">
        <div class="att-page-title">
            Lampiran Gambar &mdash; Penawaran {{ $quotation->quotation_number }}
        </div>
        @foreach($pageGroup as $att)
            @php
                $descText = trim((string) $att->description);
                $descLen = mb_strlen($descText);
                $blockClass = match (true) {
                    $descLen === 0  => 'no-desc',
                    $descLen < 100  => 'text-short',
                    default         => '',
                };
            @endphp
            <div class="att-block {{ $blockClass }}">
                <div class="att-img-wrap">
                    <img src="{{ $att->image_data_uri }}" alt="{{ $att->title }}">
                </div>
                <div class="att-text-wrap">
                    <div class="att-title">{{ $att->title }}</div>
                    @if($descLen > 0)
                        <div class="att-desc">{{ $descText }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </article>
@endforeach

</main>{{-- /canvas --}}

<aside class="toolbar">
    <button type="button" class="btn-print" onclick="triggerPrint()">🖨️ Cetak</button>
    <a class="btn-pdf" href="{{ route('sales.quotations.pdf', $quotation->id) }}" id="btnSavePdf">💾 Simpan PDF</a>
    <a class="btn-exit" href="{{ route('sales.quotations.index') }}">⨯ Keluar</a>
    <label class="sig-toggle">
        <input type="checkbox" checked onchange="document.body.classList.toggle('no-sig', !this.checked)">
        Sertakan tanda tangan
    </label>
    <label class="sig-toggle">
        <input type="checkbox" checked onchange="document.body.classList.toggle('no-name', !this.checked)">
        Sertakan nama
    </label>
</aside>

</div>{{-- /shell --}}

<script>
    let _redirectAfterPrint = false;
    const _indexUrl = @json(route('sales.quotations.index'));

    function triggerPrint() {
        _redirectAfterPrint = true;
        window.print();
    }
    window.addEventListener('afterprint', function () {
        if (_redirectAfterPrint) {
            window.location.href = _indexUrl;
        }
    });

    // Thumbnail nav: klik untuk scroll ke halaman terkait.
    (function () {
        const thumbs = document.querySelectorAll('.thumb');
        const canvas = document.getElementById('canvas');
        thumbs.forEach((t) => {
            t.addEventListener('click', () => {
                const target = document.getElementById(t.dataset.target);
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                thumbs.forEach((x) => x.classList.remove('active'));
                t.classList.add('active');
            });
        });

        // Sinkronisasi active state saat user scroll manual.
        if (canvas && 'IntersectionObserver' in window) {
            const obs = new IntersectionObserver((entries) => {
                entries.forEach((e) => {
                    if (!e.isIntersecting) return;
                    const id = e.target.id;
                    thumbs.forEach((x) => {
                        x.classList.toggle('active', x.dataset.target === id);
                    });
                });
            }, { root: canvas, threshold: 0.4 });
            document.querySelectorAll('.paper').forEach((p) => obs.observe(p));
        }
    })();
</script>

</body>
</html>
