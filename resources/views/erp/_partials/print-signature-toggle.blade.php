{{-- Toggle "Sertakan tanda tangan / nama" untuk toolbar print (SO, Penawaran, Invoice).
     Memasang/melepas kelas .no-sig / .no-name di <body>. CSS efeknya ada di
     print-styles-accurate (.sig-section) & inline pada penawaran (.signature-block). --}}
<style>
    .sig-toggle {
        display: flex; align-items: center; gap: 8px;
        margin-top: 4px; padding: 9px 12px;
        background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px;
        font-size: 12px; font-weight: 600; color: #334155; cursor: pointer;
        user-select: none;
    }
    .sig-toggle input { width: 16px; height: 16px; cursor: pointer; margin: 0; }
</style>
<label class="sig-toggle">
    <input type="checkbox" checked onchange="document.body.classList.toggle('no-sig', !this.checked)">
    Sertakan tanda tangan
</label>
<label class="sig-toggle">
    <input type="checkbox" checked onchange="document.body.classList.toggle('no-name', !this.checked)">
    Sertakan nama
</label>
