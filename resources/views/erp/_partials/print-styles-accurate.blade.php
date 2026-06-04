{{--
    Body styles untuk dokumen print:
    - Header gaya Accurate (lihat print-header-accurate.blade.php)
    - Items table & summary mengikuti pola Quotation (light blue header,
      summary-wrap dengan notes-box + totals-box rounded)
    Include 1x di dalam <article class="paper"> sebelum konten.
--}}
<style>
    .recipient-block { margin: 4px 0 14px 0; }
    .recipient-block .lbl { font-size: 11px; color: #64748b; margin-bottom: 2px; }
    .recipient-block .name { font-size: 14px; font-weight: 700; color: #0f172a; }
    .recipient-block .addr {
        color: #475569; white-space: pre-line;
        font-size: 12px; margin-top: 8px;
    }

    /* === Items table — quotation style === */
    table.items-acc {
        width: 100%;
        border-collapse: collapse;
        margin: 8px 0 0 0;
        font-size: 12px;
    }
    table.items-acc th, table.items-acc td {
        border: 1px solid #94a3b8;
        padding: 5px 6px;
        text-align: left; vertical-align: top;
    }
    table.items-acc th {
        background: #f1f5f9;
        font-weight: 700; color: #334155;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    table.items-acc td.num, table.items-acc th.num { text-align: right; }
    table.items-acc td.center, table.items-acc th.center { text-align: center; }
    table.items-acc .sku-prefix {
        font-family: monospace; font-weight: 700; font-size: 11px;
    }
    table.items-acc tr.extras-head td {
        background: #f8fafc;
        font-weight: 700; color: #334155;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* === Summary — quotation style === */
    .summary-wrap { display: flex; gap: 16px; margin-top: 14px; align-items: flex-start; }
    .summary-wrap .notes-box { flex: 1; padding: 4px 0; }
    .summary-wrap .notes-box .h {
        font-size: 12px; font-weight: 700; color: #0f172a;
        margin-bottom: 4px;
    }
    .summary-wrap .notes-box .body {
        white-space: pre-wrap; color: #334155;
        min-height: 40px;
    }

    .summary-wrap .totals-box {
        width: 280px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 10px 12px;
        background: #fff;
    }
    .summary-wrap .totals-box .row {
        display: flex; justify-content: space-between;
        padding: 3px 0;
        color: #475569;
    }
    .summary-wrap .totals-box .row .neg { color: #ef4444; }
    .summary-wrap .totals-box hr {
        border: 0;
        border-top: 1px solid #e5e7eb;
        margin: 6px 0;
    }
    .summary-wrap .totals-box .grand {
        display: flex; justify-content: space-between;
        padding: 6px 0;
        font-weight: 800; font-size: 14px; color: #0f172a;
    }
    .summary-wrap .totals-box .row.paid { color: #166534; }
    .summary-wrap .totals-box .row.remaining {
        color: #92400e; font-weight: 700;
    }

    /* === Signature === */
    .sig-section { margin-top: 22px; }
    .sig-section .sig-greeting { color: #1e293b; }
    .sig-section .sig-block { margin-top: 8px; }
    .sig-section .sig-img { height: 70px; max-width: 220px; display: block; margin: 4px 0; }
    .sig-section .sig-name { font-weight: 700; text-decoration: underline; color: #0f172a; }
    .sig-section .sig-title { color: #475569; font-size: 11px; }
</style>
