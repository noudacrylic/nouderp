/*
 * Timbangan — pembaca timbangan CAS SW-II (RS232 → USB) lewat Web Serial, plus mode
 * simulasi untuk dikembangkan & dites sebelum alatnya datang.
 *
 * Protokol dari manual CAS Australia, BELUM diverifikasi dengan unit asli. Semua yang
 * belum pasti (posisi field, desimal, cakupan BCC) dibaca dari config/qc.php, dan setiap
 * paket mentah dilaporkan sebagai hex lewat onRaw — panel Diagnosa di halaman QC
 * menggantikan Termite untuk memverifikasi protokol.
 *
 *   SOH(01) STX(02) STA('S'|'U') SIGN('-'|' ') W5..W0 UN1 UN2 BCC ETX(03) EOT(04)
 *
 * Web Serial hanya ada di Chrome/Edge desktop dan hanya di HTTPS / localhost.
 */
(function (root) {
    'use strict';

    const hex = (bytes) => Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join(' ');

    /** Urai satu paket (SOH … EOT). Hasil null bila paketnya tidak bisa dibaca. */
    function parseFrame(bytes, p) {
        const b = Array.from(bytes);
        if (b.length < p.unit_start + 2) return null;

        const ch = (i) => String.fromCharCode(b[i]);
        const stable = ch(p.status_index) === 'S';
        const negatif = ch(p.sign_index) === '-';

        const teks = b.slice(p.weight_start, p.weight_start + p.weight_length).map((x) => String.fromCharCode(x)).join('').trim();
        let nilai;
        if (p.decimals === 'auto' || p.decimals === null || p.decimals === undefined) {
            nilai = parseFloat(teks);
        } else {
            nilai = parseInt(teks.replace(/\D/g, ''), 10) / Math.pow(10, Number(p.decimals));
        }
        if (!isFinite(nilai)) return null;

        const unit = (ch(p.unit_start) + ch(p.unit_start + 1)).trim().toUpperCase();
        const faktor = unit === 'KG' ? 1000 : (unit === 'LB' ? 453.59237 : 1);
        let gram = Math.round(nilai * faktor * 10) / 10;
        if (negatif) gram = -gram;

        let bccOk = true;
        if (p.bcc && p.bcc !== 'off') {
            const dari = p.bcc === 'stx_to_unit' ? 1 : p.status_index;
            let x = 0;
            for (let i = dari; i <= p.unit_start + 1; i++) x ^= b[i];
            bccOk = x === b[p.bcc_index];
        }

        return { stable, gram, unit: unit || 'G', bccOk, raw: hex(b) };
    }

    /**
     * Urai satu baris ASCII (format unit asli CAS SW-II yang kita pakai):
     *   "  0.8555kg\r\n" — dikirim terus ~10×/detik, tanpa flag stabil & tanpa BCC.
     * Stabil tidaknya dihitung dari beberapa bacaan terakhir (lihat create → terima).
     */
    function parseLine(bytes) {
        const b = Array.from(bytes);
        const teks = b.filter((x) => x >= 0x20 && x < 0x7f).map((x) => String.fromCharCode(x)).join('');
        const m = teks.match(/([+-])?\s*(\d+(?:[.,]\d+)?)\s*(kg|g|lb)?\s*$/i);
        if (!m) return null;
        const nilai = parseFloat(m[2].replace(',', '.'));
        if (!isFinite(nilai)) return null;
        const unit = (m[3] || 'g').toUpperCase();
        const faktor = unit === 'KG' ? 1000 : (unit === 'LB' ? 453.59237 : 1);
        let gram = Math.round(nilai * faktor * 10) / 10;
        if (m[1] === '-') gram = -gram;
        return { stable: true, gram, unit, bccOk: true, raw: hex(b) };
    }

    /**
     * Pemantau tangkap otomatis: berat stabil di atas ambang selama stable_ms dengan
     * simpangan ≤ jitter → satu tangkapan. Tangkapan berikutnya baru setelah timbangan
     * kembali mendekati nol (bungkus diangkat), supaya satu bungkus tak tercatat dua kali.
     */
    function buatPenangkap(c, onCapture) {
        let siap = true;
        let jendela = [];

        return function (r, now) {
            if (Math.abs(r.gram) <= c.zero_gram) { siap = true; jendela = []; return; }
            if (!siap || !r.stable || r.gram < c.min_gram) { jendela = []; return; }

            jendela.push({ t: now, g: r.gram, raw: r.raw });
            jendela = jendela.filter((x) => now - x.t <= c.stable_ms * 2);

            const min = Math.min(...jendela.map((x) => x.g));
            const max = Math.max(...jendela.map((x) => x.g));
            if (max - min > c.jitter_gram) { jendela = [jendela[jendela.length - 1]]; return; }

            if (now - jendela[0].t >= c.stable_ms) {
                siap = false;
                const rata = jendela.reduce((s, x) => s + x.g, 0) / jendela.length;
                onCapture({ gram: Math.round(rata * 10) / 10, raw: jendela.map((x) => x.raw).join('\n') });
                jendela = [];
            }
        };
    }

    function create(opts) {
        const cfg = opts.config || {};
        const p = cfg.parser;
        const on = {
            reading: opts.onReading || (() => {}),
            capture: opts.onCapture || (() => {}),
            status: opts.onStatus || (() => {}),
            raw: opts.onRaw || (() => {}),
        };

        let port = null, reader = null, writer = null, pollTimer = null, simTimer = null;
        let berjalan = false;
        let sim = { gram: 0, stable: true };
        let penangkap = buatPenangkap(cfg.capture, (c) => on.capture(c));

        const modeBaris = p.format === 'line';
        const urai = (bytes) => (modeBaris ? parseLine(bytes) : parseFrame(bytes, p));

        // Format baris tak punya flag stabil → stabil bila bacaan ±stable_ms/2 terakhir
        // berselisih ≤ jitter (minimal 3 bacaan).
        let riwayat = [];
        const nilaiStabil = (r, now) => {
            if (!modeBaris) return r;
            riwayat.push({ t: now, g: r.gram });
            riwayat = riwayat.filter((x) => now - x.t <= cfg.capture.stable_ms / 2);
            const gs = riwayat.map((x) => x.g);
            return { ...r, stable: gs.length >= 3 && Math.max(...gs) - Math.min(...gs) <= cfg.capture.jitter_gram };
        };

        const terima = (r) => {
            const now = Date.now();
            r = nilaiStabil(r, now);
            on.reading(r);
            penangkap(r, now);
        };

        let rawTerakhir = '';
        const lapor = (bytes, r) => {
            const h = hex(bytes) + (r && !r.bccOk ? '   ← BCC tidak cocok' : (r ? `   → ${r.gram} g` : '   ← tak terbaca'));
            if (h !== rawTerakhir) on.raw(h);   // baris kembar tak dicatat ulang (alat kirim ~10×/detik)
            rawTerakhir = h;
        };

        async function bacaTerus() {
            let buf = [];
            while (berjalan && port && port.readable) {
                reader = port.readable.getReader();
                try {
                    for (;;) {
                        const { value, done } = await reader.read();
                        if (done) break;
                        for (const byte of value) {
                            if (!modeBaris && byte === p.start_byte) buf = [];
                            buf.push(byte);
                            if (byte === p.end_byte) {
                                const r = urai(buf);
                                lapor(buf, r);
                                if (r && r.bccOk) terima(r);
                                buf = [];
                            }
                            if (buf.length > 64) { on.raw(hex(buf) + '   ← tanpa penutup'); buf = []; }
                        }
                    }
                } catch (e) {
                    on.status('Koneksi terputus: ' + e.message);
                } finally {
                    reader.releaseLock();
                }
            }
        }

        async function buka(pt) {
            port = pt;
            await port.open({
                baudRate: cfg.serial.baud_rate, dataBits: cfg.serial.data_bits,
                parity: cfg.serial.parity, stopBits: cfg.serial.stop_bits,
            });
            berjalan = true;
            if (cfg.serial.poll_ms > 0 && port.writable) {
                writer = port.writable.getWriter();
                pollTimer = setInterval(() => writer.write(new Uint8Array([0x11])).catch(() => {}), cfg.serial.poll_ms);
            }
            on.status('Terhubung ke timbangan');
            bacaTerus();
        }

        return {
            didukung: () => 'serial' in navigator,

            /** Sambung ulang otomatis ke port yang dulu sudah diizinkan (tanpa klik). */
            async sambungUlang() {
                if (!('serial' in navigator)) return false;
                const ports = await navigator.serial.getPorts();
                if (!ports.length) return false;
                try { await buka(ports[0]); return true; } catch (e) { on.status('Gagal membuka port: ' + e.message); return false; }
            },

            /** Pilih port (wajib dari klik pengguna, aturan peramban). */
            async sambung() {
                if (!('serial' in navigator)) {
                    on.status('Peramban ini tidak mendukung Web Serial — pakai Chrome/Edge di PC lewat HTTPS.');
                    return false;
                }
                try { await buka(await navigator.serial.requestPort()); return true; }
                catch (e) { on.status('Batal / gagal membuka port: ' + e.message); return false; }
            },

            async putus() {
                berjalan = false;
                clearInterval(pollTimer);
                try { if (writer) { writer.releaseLock(); writer = null; } } catch (e) {}
                try { if (reader) await reader.cancel(); } catch (e) {}
                try { if (port) await port.close(); } catch (e) {}
                port = null;
                on.status('Terputus');
            },

            // ── simulasi ──
            mulaiSimulasi() {
                clearInterval(simTimer);
                simTimer = setInterval(() => {
                    // Simulasi membawa flag stabilnya sendiri (tombol "goyang"), tak dihitung ulang.
                    const r = { stable: sim.stable, gram: sim.gram, unit: 'G', bccOk: true, raw: 'simulasi' };
                    on.reading(r);
                    penangkap(r, Date.now());
                }, 200);
                on.status('Mode simulasi');
            },
            hentikanSimulasi() { clearInterval(simTimer); },
            aturSimulasi(gram, stable = true) { sim = { gram: Math.round(gram * 10) / 10, stable }; },
            bacaanSimulasi() { return { ...sim }; },

            /** Putar ulang hex mentah (mis. tempelan dari Termite) untuk menguji parser. */
            putarHex(teks) {
                const bytes = teks.trim().split(/[\s,]+/).filter(Boolean).map((h) => parseInt(h, 16));
                const r = urai(bytes);
                on.raw(hex(bytes) + (r ? `   → ${r.gram} g ${r.stable ? 'stabil' : 'belum stabil'}${r.bccOk ? '' : ' (BCC tidak cocok)'}` : '   ← tak terbaca'));
                return r;
            },

            /** Siapkan tangkapan berikutnya tanpa menunggu timbangan kosong. */
            siapkanUlang() { penangkap = buatPenangkap(cfg.capture, (c) => on.capture(c)); },
        };
    }

    const api = { create, parseFrame, parseLine, buatPenangkap };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.Timbangan = api;
})(typeof window !== 'undefined' ? window : globalThis);
