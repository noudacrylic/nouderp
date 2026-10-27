/*
 * Tes parser & tangkap otomatis timbangan.js — jalankan: node tests/js/timbangan.test.cjs
 *
 * Paket di sini BUATAN, disusun dari manual CAS. Begitu timbangan asli datang, tambahkan
 * hex dari Termite / panel Diagnosa sebagai kasus baru dan sesuaikan config/qc.php.
 */
const assert = require('assert');
// Dimuat seperti di peramban (skrip biasa yang memasang window.Timbangan).
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const sandbox = { navigator: {} };
sandbox.window = sandbox;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/timbangan.js'), 'utf8'), sandbox);
const { parseFrame, parseLine, buatPenangkap } = sandbox.Timbangan;
const bytes = (h) => h.trim().split(/\s+/).map((x) => parseInt(x, 16));

const parser = {
    start_byte: 0x01, end_byte: 0x04, status_index: 2, sign_index: 3, weight_start: 4,
    weight_length: 6, unit_start: 10, bcc_index: 12, decimals: 'auto', bcc: 'off',
};

function paket(sta, sign, berat, unit) {
    const b = [0x01, 0x02, sta.charCodeAt(0), sign.charCodeAt(0), ...berat.split('').map((c) => c.charCodeAt(0)),
               ...unit.split('').map((c) => c.charCodeAt(0))];
    let x = 0;
    for (let i = 1; i < b.length; i++) x ^= b[i];
    return [...b, x, 0x03, 0x04];
}

let lulus = 0;
const uji = (nama, fn) => { fn(); lulus++; console.log('✓', nama); };

uji('kg dengan titik → gram', () => {
    const r = parseFrame(paket('S', ' ', '0.8555', 'KG'), parser);
    assert.strictEqual(r.gram, 855.5);
    assert.strictEqual(r.stable, true);
});

uji('status U = belum stabil', () => {
    assert.strictEqual(parseFrame(paket('U', ' ', '0.8555', 'KG'), parser).stable, false);
});

uji('tanda minus', () => {
    assert.strictEqual(parseFrame(paket('S', '-', '0.0012', 'KG'), parser).gram, -1.2);
});

uji('desimal implisit (tanpa titik)', () => {
    const r = parseFrame(paket('S', ' ', '008555', ' G'), { ...parser, decimals: 1 });
    assert.strictEqual(r.gram, 855.5);
});

uji('BCC stx_to_unit cocok & rusak', () => {
    const p = { ...parser, bcc: 'stx_to_unit' };
    const ok = paket('S', ' ', '0.8555', 'KG');
    assert.strictEqual(parseFrame(ok, p).bccOk, true);
    const rusak = [...ok]; rusak[12] ^= 0xff;
    assert.strictEqual(parseFrame(rusak, p).bccOk, false);
});

uji('tangkap sekali saat stabil, siap lagi setelah kembali nol', () => {
    const tangkap = [];
    const c = { min_gram: 2, stable_ms: 800, jitter_gram: 0.2, zero_gram: 1 };
    const f = buatPenangkap(c, (x) => tangkap.push(x.gram));
    for (let t = 0; t <= 2000; t += 200) f({ gram: 855.1, stable: true, raw: '' }, t);
    assert.deepStrictEqual(tangkap, [855.1]);
    f({ gram: 0, stable: true, raw: '' }, 2200);
    for (let t = 2400; t <= 3400; t += 200) f({ gram: 736, stable: true, raw: '' }, t);
    assert.deepStrictEqual(tangkap, [855.1, 736]);
});

uji('goyang melebihi jitter tidak ditangkap', () => {
    const tangkap = [];
    const f = buatPenangkap({ min_gram: 2, stable_ms: 800, jitter_gram: 0.2, zero_gram: 1 }, (x) => tangkap.push(x));
    [850, 851, 850, 851, 850, 851].forEach((g, i) => f({ gram: g, stable: true, raw: '' }, i * 200));
    assert.strictEqual(tangkap.length, 0);
});

// ── Hex ASLI dari unit kita (COM3, 22 Okt 2026): baris ASCII, tanpa flag stabil/BCC ──

uji('asli: "  0.0016kg" → 1,6 g', () => {
    const r = parseLine(bytes('20 20 30 2e 30 30 31 36 6b 67 0d 0a'));
    assert.strictEqual(r.gram, 1.6);
    assert.strictEqual(r.unit, 'KG');
});

uji('asli: "  0.0000kg" → 0 g', () => {
    assert.strictEqual(parseLine(bytes('20 20 30 2e 30 30 30 30 6b 67 0d 0a')).gram, 0);
});

uji('baris: kg → gram & minus', () => {
    const teks = (t) => [...t].map((c) => c.charCodeAt(0)).concat([0x0d, 0x0a]);
    assert.strictEqual(parseLine(teks('  0.8555kg')).gram, 855.5);
    assert.strictEqual(parseLine(teks(' -0.0012kg')).gram, -1.2);
    assert.strictEqual(parseLine(teks('-  0.0012kg')).gram, -1.2);
});

uji('baris: sampah awal port (02 0a / d9 6b 67 0d 0a) tak terbaca', () => {
    assert.strictEqual(parseLine(bytes('02 0a')), null);
    assert.strictEqual(parseLine(bytes('d9 6b 67 0d 0a')), null);
});

uji('asli: rekaman taruh/angkat 2× (±15 g, kedip 0,1 g) → tepat 2 tangkapan', () => {
    // [baris ke-n, berat kg] — baris berubah saja; di antaranya alat mengulang nilai yang sama (~10×/detik).
    const rekaman = [[1, 0], [100, 0.0036], [101, 0.0063], [102, 0.0127], [103, 0.0152], [119, 0.0151],
        [132, 0.0152], [138, 0.0151], [176, 0.0152], [182, 0.0049], [183, 0], [311, 0.0010], [312, 0.0030],
        [313, 0.0152], [319, 0.0151], [353, 0.0152], [369, 0.0151], [372, 0.0152], [400, 0.0151],
        [435, 0.0168], [438, 0.0038], [439, 0], [556, 0.0014], [557, 0.0033], [558, 0.0049]];
    const tangkap = [];
    const f = buatPenangkap({ min_gram: 2, stable_ms: 800, jitter_gram: 0.2, zero_gram: 1 }, (x) => tangkap.push(x.gram));
    let kg = 0, i = 0;
    for (let n = 1; n <= 560; n++) {
        if (i < rekaman.length && rekaman[i][0] === n) kg = rekaman[i++][1];
        const teks = `  ${kg.toFixed(4)}kg`;
        f(parseLine([...teks].map((c) => c.charCodeAt(0)).concat([0x0d, 0x0a])), n * 100);
    }
    assert.strictEqual(tangkap.length, 2);
    tangkap.forEach((g) => assert.ok(g >= 15.1 && g <= 15.2, `tangkapan ${g}`));
});

console.log(`\n${lulus} tes lolos`);
