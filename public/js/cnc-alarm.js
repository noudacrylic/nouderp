/*
 * Alarm "MESIN SELESAI" untuk perangkat CNC (28 Nov 2026).
 *
 * Aktif di perangkat yang stasiun Intercom-nya "cnc", atau yang dinyalakan manual dari halaman
 * Operator CNC (localStorage cnc.alarm = '1'; '0' = dimatikan). Tiap 10 detik bertanya ke server
 * mesin mana yang sudah selesai memotong tapi belum disentuh operator, lalu menampilkan popup
 * besar + bunyi. Popup hilang sendiri begitu mesin mulai memotong / kepalanya digerakkan.
 * "Oke, saya ke sana" menunda pengingat mesin itu 3 menit.
 */
(function () {
    'use strict';

    const CFG = window.CNC_ALARM_CFG;
    if (!CFG) return;

    function baca(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function tulis(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

    function aktif() {
        const manual = baca('cnc.alarm');
        if (manual === '0') return false;
        return manual === '1' || baca('intercom.stasiun') === 'cnc';
    }

    const TANYA_MS = 10000;
    const TUNDA_MS = 3 * 60 * 1000;
    const BUNYI_MS = 20000;

    let menunggu = [];
    let terakhirBunyi = 0;
    let audio = null;

    function tundaan() {
        try { return JSON.parse(baca('cnc.alarm.tunda') || '{}'); } catch (e) { return {}; }
    }
    function kunci(m) { return m.id + '@' + m.selesai; }
    function ditunda(m) { return (tundaan()[kunci(m)] || 0) > Date.now(); }

    function nada(frek, mulai, lama, vol) {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') audio.resume();
            const o = audio.createOscillator(), g = audio.createGain();
            o.type = 'square'; o.frequency.value = frek;
            g.gain.setValueAtTime(vol, audio.currentTime + mulai);
            g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + mulai + lama);
            o.connect(g); g.connect(audio.destination);
            o.start(audio.currentTime + mulai); o.stop(audio.currentTime + mulai + lama + 0.05);
        } catch (e) {}
    }
    function bunyi(keras) {
        const v = keras ? 0.6 : 0.35;
        for (let i = 0; i < (keras ? 4 : 3); i++) { nada(1046, i * 0.45, 0.2, v); nada(784, i * 0.45 + 0.2, 0.2, v); }
    }

    function menit(detik) {
        const m = Math.floor(detik / 60);
        return m < 1 ? 'baru saja' : m + ' menit lalu';
    }

    function render() {
        let el = document.getElementById('cncAlarm');
        const tampil = menunggu.filter((m) => !ditunda(m));
        if (!tampil.length) { if (el) el.remove(); return; }
        if (!el) {
            el = document.createElement('div');
            el.id = 'cncAlarm';
            el.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;'
                + 'background:rgba(185,28,28,.94);color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:24px';
            document.body.appendChild(el);
        }
        const lama = tampil.some((m) => m.detik >= 300);
        el.innerHTML = '<div style="max-width:900px;width:100%">'
            + tampil.map((m) => '<div style="margin-bottom:28px">'
                + '<div style="font-size:clamp(44px,9vw,110px);font-weight:900;line-height:1;letter-spacing:-1px">⚙ ' + esc(m.nama).toUpperCase() + ' SELESAI</div>'
                + '<div style="font-size:clamp(20px,3.5vw,36px);margin-top:10px;opacity:.95">selesai ' + esc(m.selesai) + ' · <b>' + menit(m.detik) + '</b></div>'
                + '</div>').join('')
            + '<div style="font-size:clamp(16px,2.4vw,24px);opacity:.9;margin-bottom:22px">Ambil hasil & pasang lembar berikutnya. Popup hilang sendiri saat mesin mulai lagi.</div>'
            + '<button type="button" id="cncAlarmOke" style="font-size:clamp(20px,3vw,30px);font-weight:800;padding:18px 40px;border-radius:18px;'
            + 'border:0;background:#fff;color:#b91c1c;cursor:pointer">Oke, saya ke sana</button>'
            + (lama ? '<div style="margin-top:16px;font-size:16px;opacity:.85">⚠ Sudah lebih dari 5 menit</div>' : '')
            + '</div>';
        document.getElementById('cncAlarmOke').onclick = function () {
            const t = tundaan();
            tampil.forEach((m) => { t[kunci(m)] = Date.now() + TUNDA_MS; });
            // Buang tundaan lama supaya tidak menumpuk.
            Object.keys(t).forEach((k) => { if (t[k] < Date.now() - 3600000) delete t[k]; });
            tulis('cnc.alarm.tunda', JSON.stringify(t));
            render();
        };
        if (document.visibilityState === 'visible' && Date.now() - terakhirBunyi > BUNYI_MS) {
            terakhirBunyi = Date.now();
            bunyi(lama);
        }
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async function tanya() {
        if (!aktif()) { menunggu = []; render(); return; }
        try {
            const r = await fetch(CFG.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) return;
            const j = await r.json();
            const baru = (j.menunggu || []).filter((m) => !menunggu.some((x) => kunci(x) === kunci(m)) && !ditunda(m));
            if (baru.length) terakhirBunyi = 0; // mesin baru selesai → langsung bunyi
            menunggu = j.menunggu || [];
            render();
        } catch (e) { /* jaringan putus: coba lagi di putaran berikut */ }
    }

    // Ketukan pertama membuka izin suara peramban (autoplay).
    document.addEventListener('pointerdown', function buka() {
        try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); audio.resume(); } catch (e) {}
    }, { once: true });

    window.CncAlarm = {
        aktif: aktif,
        atur: function (nyala) { tulis('cnc.alarm', nyala ? '1' : '0'); tanya(); },
        tes: function () { bunyi(false); },
    };

    tanya();
    setInterval(tanya, TANYA_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tanya(); });
})();
