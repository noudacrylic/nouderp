@once
<style>
    /* Ukuran kertas label thermal 100 x 150 mm — diset via print shell lewat variabel
       pageSize = '100mm 150mm' (di header view). Sengaja tidak deklarasi ukuran halaman
       di sini supaya tidak bentrok dengan ukuran A4 default milik shell. */

    /* Override .paper dari print shell (yang default A4) khusus untuk label resi. */
    .resi-paper.paper {
        width: 100mm;
        min-height: 150mm;
        padding: 4mm;
        display: flex; flex-direction: column; align-items: stretch; gap: 0;
    }

    .resi-label {
        width: 100%; max-width: 100%;
        border: 1px solid #111; border-radius: 4px; padding: 8px;
        font-family: Arial, Helvetica, sans-serif; color: #111;
        font-size: 11px; line-height: 1.35;
    }
    .resi-label svg { max-width: 100%; height: auto; }
    .resi-label .rrow { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
    .resi-label .rcourier { font-size: 17px; font-weight: 800; text-transform: uppercase; }
    .resi-label .rpill { font-size: 9px; font-weight: bold; border: 1px solid #111; border-radius: 4px; padding: 2px 5px; text-transform: uppercase; }
    .resi-label .rservice { font-size: 10px; color: #555; }
    .resi-label .rbarcode-wrap { text-align: center; border-top: 1px dashed #999; border-bottom: 1px dashed #999; margin: 6px 0; padding: 5px 0; }
    .resi-label .rresi-num { font-family: monospace; font-size: 13px; font-weight: bold; letter-spacing: 1px; margin-top: 1px; }
    .resi-label .rbox { border: 1px solid #111; border-radius: 4px; padding: 6px; margin-top: 6px; }
    .resi-label .rbox h4 { margin: 0 0 3px; font-size: 9px; text-transform: uppercase; color: #555; letter-spacing: .5px; }
    .resi-label .rname { font-weight: bold; font-size: 12px; }
    .resi-label .raddr { font-size: 10.5px; line-height: 1.35; }
    .resi-label .rbox.to .rname { font-size: 13px; }
    .resi-label table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10.5px; }
    .resi-label th, .resi-label td { border-bottom: 1px solid #eee; padding: 3px 2px; text-align: left; }
    .resi-label th:last-child, .resi-label td:last-child { text-align: right; }
    .resi-label .rmeta { display: flex; justify-content: space-between; font-size: 9px; color: #555; margin-top: 6px; }

    @media print {
        .resi-paper.paper { width: 100mm; min-height: 150mm; padding: 3mm; box-shadow: none; }
        .resi-label { max-width: 100%; }
    }
</style>
@endonce
