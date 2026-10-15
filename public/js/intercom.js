/*
 * Intercom divisi ERP — suara dua arah antar stasiun lewat WebRTC.
 *
 * Pembagian kerja:
 *  - SINYAL (memanggil, SDP, ICE, tutup) dikirim antar peramban sebagai client
 *    event Reverb di saluran privat `private-intercom`. Server hanya meneruskan.
 *  - SUARA mengalir langsung antar perangkat di Wi-Fi kantor (WebRTC P2P).
 *
 * Satu perangkat = satu stasiun, disimpan di localStorage. Bila ERP terbuka di
 * beberapa tab, hanya SATU tab yang menjadi "mesin" (memegang Web Lock): dia
 * yang terhubung ke Reverb, menjawab panggilan dan memutar suara. Tab lain
 * menampilkan keadaan yang sama lewat BroadcastChannel dan meneruskan klik ke
 * tab mesin — tanpa ini, satu stasiun dengan 3 tab akan menjawab 3 kali.
 *
 * Panggilan langsung tersambung (auto-answer) kecuali stasiun penerima
 * menyalakan "Jangan Ganggu": maka ia berdering dan harus diangkat manual.
 */
(function () {
    'use strict';

    const CFG = window.INTERCOM_CFG;
    if (!CFG) return;

    const STASIUN = CFG.stasiun;            // { admin: {label, ikon}, ... }
    const DETAK_MS = 15000;                 // kirim "hadir" tiap 15 dtk
    const ONLINE_MS = 50000;                // dianggap offline bila diam > 50 dtk
    const SIMPAN = {
        stasiun: 'intercom.stasiun',
        dev: 'intercom.dev',
        dnd: 'intercom.dnd',
        volume: 'intercom.volume',
    };

    // ───────────────────────── util ─────────────────────────
    function baca(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function tulis(k, v) {
        try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {}
    }
    function acak() { return Math.random().toString(36).slice(2, 10) + Date.now().toString(36); }
    function label(k) { return STASIUN[k] ? STASIUN[k].label : k; }
    function ikon(k) { return STASIUN[k] ? STASIUN[k].ikon : '📢'; }
    function esc(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function durasi(ms) {
        const d = Math.max(0, Math.floor(ms / 1000));
        return String(Math.floor(d / 60)).padStart(2, '0') + ':' + String(d % 60).padStart(2, '0');
    }

    let devId = baca(SIMPAN.dev);
    if (!devId) { devId = acak(); tulis(SIMPAN.dev, devId); }

    // ───────────────────────── suara (Web Audio) ─────────────────────────
    // AudioContext baru boleh berbunyi setelah ada interaksi di halaman, jadi
    // dibuka pada klik/ketukan pertama.
    let audioCtx = null;
    function ctx() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return null;
                audioCtx = new AC();
            }
            if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
        } catch (e) { return null; }
        return audioCtx;
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach(ev =>
        window.addEventListener(ev, () => ctx(), { capture: true, passive: true }));

    function nada(frek, mulai, lama, vol) {
        const c = ctx();
        if (!c) return;
        const t0 = c.currentTime + mulai;
        const o = c.createOscillator();
        const g = c.createGain();
        o.type = 'sine';
        o.frequency.value = frek;
        g.gain.setValueAtTime(0.0001, t0);
        g.gain.exponentialRampToValueAtTime(vol || 0.35, t0 + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + lama);
        o.connect(g).connect(c.destination);
        o.start(t0);
        o.stop(t0 + lama + 0.05);
    }
    // "Ding-dong" sebelum mikrofon penerima terbuka.
    function dingDong() { nada(880, 0, 0.55); nada(660, 0.5, 0.7); }
    function nadaTutup() { nada(480, 0, 0.15, 0.25); nada(380, 0.18, 0.25, 0.25); }
    let deringTimer = null;
    function deringMulai() {
        deringBerhenti();
        const satu = () => { nada(740, 0, 0.25); nada(740, 0.35, 0.25); };
        satu();
        deringTimer = setInterval(satu, 2000);
    }
    function deringBerhenti() { if (deringTimer) { clearInterval(deringTimer); deringTimer = null; } }

    // ───────────────────────── keadaan bersama ─────────────────────────
    // Satu objek yang dirender oleh SEMUA tab. Tab mesin yang memegang versi
    // asli dan menyiarkannya; tab lain hanya menyalin.
    let keadaan = {
        stasiun: baca(SIMPAN.stasiun) || null,
        dnd: baca(SIMPAN.dnd) === '1',
        // Penguat suara lawan bicara (1 = 100%). <audio>.volume mentok di 100%,
        // jadi di atas itu suara dilewatkan GainNode Web Audio.
        volume: Math.min(4, Math.max(1, parseFloat(baca(SIMPAN.volume)) || 2)),
        terhubung: false,       // WebSocket ke Reverb
        adaMesin: false,        // ada tab mesin yang aktif
        hadir: {},              // { stasiun: {dev, dnd, sibuk, t} }
        konflik: false,         // stasiun yang sama dipakai perangkat lain
        panggilan: null,        // {peran, lawan, status, mulai, tersambungAt, bisu}
        pesan: null,            // pesan singkat untuk pengguna
        mic: 'unknown',         // izin mikrofon: granted | denied | prompt | unknown
        perluKetuk: false,      // suara diblok autoplay → minta ketukan
        tesMic: null,           // {label} selama tes mikrofon berjalan
    };

    const tabKanal = ('BroadcastChannel' in window) ? new BroadcastChannel('intercom-tab') : null;
    let sayaMesin = false;
    let mesin = null;           // objek mesin bila tab ini pemegang kunci

    function siarkan() {
        render();
        if (sayaMesin && tabKanal) tabKanal.postMessage({ jenis: 'keadaan', data: keadaan });
    }
    function setKeadaan(ubah) { Object.assign(keadaan, ubah); siarkan(); }

    let pesanTimer = null;
    function beriPesan(teks, lama) {
        clearTimeout(pesanTimer);
        setKeadaan({ pesan: teks });
        pesanTimer = setTimeout(() => setKeadaan({ pesan: null }), lama || 6000);
    }

    // Perintah dari UI: dijalankan langsung bila tab ini mesin, kalau tidak
    // diteruskan ke tab mesin.
    async function perintah(aksi, arg) {
        ctx();
        if (aksi === 'stasiun') return aturStasiun(arg);
        // Klik di tab ini barusan memicu perebutan mesin — tunggu selesai supaya
        // perintahnya dijalankan di sini, bukan dikirim ke mesin lama yang sedang mundur.
        if (merebut) await tunggu(() => !merebut, 3000);
        if (sayaMesin && mesin) return mesin.jalankan(aksi, arg);
        if (tabKanal) tabKanal.postMessage({ jenis: 'perintah', aksi, arg });
    }

    function aturStasiun(k) {
        k = k && STASIUN[k] ? k : null;
        tulis(SIMPAN.stasiun, k);
        keadaan.stasiun = k;
        if (sayaMesin && mesin) mesin.gantiStasiun(k);
        if (tabKanal) tabKanal.postMessage({ jenis: 'stasiun', stasiun: k });
        if (k) mintaKunci();
        render();
    }

    if (tabKanal) {
        tabKanal.onmessage = (e) => {
            const m = e.data || {};
            if (m.jenis === 'keadaan' && !sayaMesin) { keadaan = m.data; render(); }
            else if (m.jenis === 'minta-keadaan' && sayaMesin) siarkan();
            else if (m.jenis === 'perintah' && sayaMesin && mesin) mesin.jalankan(m.aksi, m.arg);
            else if (m.jenis === 'stasiun') {
                keadaan.stasiun = m.stasiun;
                if (sayaMesin && mesin) mesin.gantiStasiun(m.stasiun);
                if (m.stasiun) mintaKunci();
                render();
            }
        };
    }

    // ───────────────────────── pemilihan tab mesin ─────────────────────────
    let sudahMinta = false;
    // Kunci dipegang selama tab hidup; tutup tab = kunci lepas = tab lain yang
    // antre otomatis mengambil alih. Tab yang DIKLIK merebut kunci (rebut=true):
    // suara WebRTC hanya boleh diputar di tab yang pernah disentuh pengguna,
    // jadi mesin harus pindah ke tab yang sedang dipakai — bukan tetap di tab
    // lama yang terlupa di jendela lain dan diblokir autoplay (suara satu arah).
    let merebut = false;
    function jadiMesin() {
        if (!sayaMesin) {
            sayaMesin = true;
            mesin = buatMesin();
            mesin.mulai();
        }
        return new Promise(() => {});
    }
    function mundur() {
        if (!sayaMesin) return;
        sayaMesin = false;          // dulu, supaya berhenti() tak menyiarkan keadaan "putus"
        if (mesin) mesin.berhenti();
        mesin = null;
        if (tabKanal) tabKanal.postMessage({ jenis: 'minta-keadaan' });
        render();
        mintaKunci();               // antre lagi, siap mengambil alih bila tab baru ditutup
    }
    function mintaKunci(rebut) {
        if (!keadaan.stasiun) return;
        if (!(navigator.locks && navigator.locks.request)) {
            jadiMesin(); // peramban lama: tiap tab jadi mesin
            return;
        }
        if (rebut) {
            if (sayaMesin || merebut || keadaan.panggilan) return; // jangan pindah di tengah panggilan
            merebut = true;
        } else {
            if (sudahMinta || sayaMesin) return;
            sudahMinta = true;
        }
        navigator.locks.request('intercom-mesin', rebut ? { steal: true } : {}, () => {
            if (rebut) merebut = false; else sudahMinta = false;
            return jadiMesin();
        }).catch(() => {
            // Kunci kita direbut tab lain.
            if (rebut) merebut = false; else sudahMinta = false;
            mundur();
        });
    }
    function tunggu(cek, ms) {
        return new Promise(res => {
            const t0 = Date.now();
            (function ulang() {
                if (cek() || Date.now() - t0 > ms) return res();
                setTimeout(ulang, 50);
            })();
        });
    }
    ['pointerdown', 'keydown'].forEach(ev => window.addEventListener(ev, () => {
        if (!sayaMesin && keadaan.stasiun && !keadaan.panggilan) mintaKunci(true);
    }, { capture: true, passive: true }));

    // ───────────────────────── MESIN ─────────────────────────
    function buatMesin() {
        let pusher = null, kanal = null, detak = null;
        let pg = null;           // panggilan aktif (data internal, bukan untuk dirender)
        let audioEl = null;

        function kirim(data) {
            if (!kanal || !keadaan.stasiun) return false;
            try {
                kanal.trigger('client-sinyal', Object.assign({ dari: keadaan.stasiun, dev: devId }, data));
                return true;
            } catch (e) { return false; }
        }

        // Sinyal milik panggilan aktif. `dv` = perangkat lawan, supaya bila dua
        // perangkat keliru memakai stasiun yang sama, hanya satu yang dilayani.
        function kirimPg(p, data) {
            return kirim(Object.assign({ ke: p.lawan, id: p.id, dv: p.lawanDev }, data));
        }

        function umumkanHadir() {
            kirim({ t: 'hadir', dnd: keadaan.dnd, sibuk: !!pg });
        }

        function mulai() {
            setKeadaan({ adaMesin: true });
            cekIzinMic();
            if (keadaan.stasiun) sambung();
            window.addEventListener('pagehide', saatPergi);
            // Bersihkan stasiun yang sudah lama diam dari daftar online.
            timerBersih = setInterval(() => {
                const now = Date.now();
                let ubah = false;
                for (const k in keadaan.hadir) {
                    if (now - keadaan.hadir[k].t > ONLINE_MS) { delete keadaan.hadir[k]; ubah = true; }
                }
                if (ubah) siarkan();
            }, 5000);
            wakeLock();
        }

        let timerBersih = null;
        function saatPergi() {
            if (pg) akhiri('tersambung');
            kirim({ t: 'pergi' });
        }

        // Mesin dipindah ke tab lain (kuncinya direbut). TIDAK mengirim "pergi":
        // perangkatnya sama, tab baru langsung mengumumkan hadir dengan id yang sama.
        function berhenti() {
            if (pg) akhiri('tersambung');
            window.removeEventListener('pagehide', saatPergi);
            clearInterval(timerBersih);
            lepasWakeLock();
            putus();
            if (audioEl) { audioEl.srcObject = null; audioEl.remove(); audioEl = null; }
        }

        function sambung() {
            if (pusher) return;
            if (!window.Pusher) { beriPesan('Pustaka WebSocket gagal dimuat.'); return; }
            pusher = new window.Pusher(CFG.key, {
                wsHost: CFG.host,
                wsPort: CFG.port,
                wssPort: CFG.port,
                forceTLS: CFG.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                cluster: 'mt1',
                channelAuthorization: {
                    endpoint: CFG.authUrl,
                    transport: 'ajax',
                    headers: { 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' },
                },
            });
            pusher.connection.bind('state_change', (s) => {
                const ok = s.current === 'connected';
                if (!ok) keadaan.hadir = {};
                setKeadaan({ terhubung: ok });
            });
            kanal = pusher.subscribe('private-intercom');
            kanal.bind('pusher:subscription_succeeded', () => {
                kirim({ t: 'siapa' });
                umumkanHadir();
            });
            kanal.bind('pusher:subscription_error', () => {
                beriPesan('Gagal masuk saluran intercom (sesi login habis?). Muat ulang halaman.', 10000);
            });
            kanal.bind('client-sinyal', terima);
            detak = setInterval(umumkanHadir, DETAK_MS);
        }

        function putus() {
            clearInterval(detak);
            if (pusher) { try { pusher.disconnect(); } catch (e) {} }
            pusher = null; kanal = null;
            keadaan.hadir = {};
            setKeadaan({ terhubung: false, konflik: false });
        }

        function gantiStasiun(k) {
            if (pg) akhiri('tersambung');
            kirim({ t: 'pergi' });
            putus();
            setKeadaan({ stasiun: k });
            if (k) sambung();
        }

        // ── sinyal masuk ──
        function terima(m) {
            if (!m || !m.dari) return;

            // Kehadiran
            if (m.t === 'hadir' || m.t === 'siapa') {
                if (m.dari === keadaan.stasiun) {
                    if (m.dev !== devId) setKeadaan({ konflik: true });
                    return;
                }
                keadaan.hadir[m.dari] = { dev: m.dev, dnd: !!m.dnd, sibuk: !!m.sibuk, t: Date.now() };
                if (m.t === 'siapa') umumkanHadir();
                siarkan();
                return;
            }
            if (m.t === 'pergi') {
                if (keadaan.hadir[m.dari] && keadaan.hadir[m.dari].dev === m.dev) {
                    delete keadaan.hadir[m.dari];
                    siarkan();
                }
                if (pg && pg.lawan === m.dari && pg.lawanDev === m.dev) akhiri('tersambung', true);
                return;
            }

            // Selebihnya hanya yang ditujukan ke stasiun ini
            if (m.ke !== keadaan.stasiun) return;

            if (m.t === 'panggil') return masukPanggilan(m);
            if (!pg || m.id !== pg.id) return;
            if (m.dv && m.dv !== devId) return;
            if (pg.lawanDev && m.dev !== pg.lawanDev && m.t !== 'terima' && m.t !== 'berdering') return;

            switch (m.t) {
                case 'berdering':
                    pg.lawanDev = m.dev;
                    ubahPanggilan({ status: 'berdering' });
                    break;
                case 'terima':
                    if (pg.peran !== 'pemanggil' || pg.pc) break; // sudah dijawab perangkat lain
                    pg.lawanDev = m.dev;
                    mulaiSebagaiPemanggil();
                    break;
                case 'tolak':
                    beriPesan(m.alasan === 'sibuk'
                        ? label(pg.lawan) + ' sedang dalam panggilan lain.'
                        : label(pg.lawan) + ' menolak panggilan.');
                    akhiri(m.alasan === 'sibuk' ? 'sibuk' : 'ditolak', true);
                    break;
                case 'offer':
                    jawabOffer(m.sdp);
                    break;
                case 'answer':
                    if (pg.pc) pg.pc.setRemoteDescription(m.sdp).then(tuangIceTertunda).catch(gagal);
                    break;
                case 'ice':
                    (m.c || []).forEach(c => {
                        if (pg.pc && pg.pc.remoteDescription) pg.pc.addIceCandidate(c).catch(() => {});
                        else pg.iceTertunda.push(c);
                    });
                    break;
                case 'tutup':
                    akhiri(pg.tersambungAt ? 'tersambung' : 'tak_terjawab', true);
                    break;
            }
        }

        // ── keluar: memanggil ──
        async function panggil(ke) {
            if (!keadaan.stasiun) return beriPesan('Pilih dulu stasiun perangkat ini.');
            if (pg) return beriPesan('Masih ada panggilan berjalan.');
            if (!STASIUN[ke] || ke === keadaan.stasiun) return;
            // Mesin yang baru pindah ke tab ini perlu ±1 dtk untuk tersambung,
            // masuk saluran, lalu mendengar jawaban "siapa" dari stasiun lain.
            const siap = () => keadaan.terhubung && kanal && kanal.subscribed;
            if (!siap()) await tunggu(siap, 6000);
            if (!siap()) return beriPesan('Intercom belum tersambung ke server.');
            if (!keadaan.hadir[ke]) await tunggu(() => !!keadaan.hadir[ke], 1500);
            if (!keadaan.hadir[ke]) {
                catat(ke, 'offline', 0);
                return beriPesan('Stasiun ' + label(ke) + ' sedang offline.');
            }

            const p = pg = baru('pemanggil', ke);
            ubahPanggilan({ status: 'memanggil' });
            let stream;
            try {
                stream = await ambilMic();
            } catch (e) {
                if (pg === p) gagal(e, 'Mikrofon tidak bisa dibuka: ' + (e.message || e.name));
                return;
            }
            if (pg !== p) { stream.getTracks().forEach(t => t.stop()); return; } // dibatalkan saat menunggu izin
            p.stream = stream;
            kirim({ t: 'panggil', ke, id: p.id });
            // Penerima auto-answer membalas dalam ±2 dtk; yang "Jangan Ganggu"
            // diberi waktu sampai batas dering.
            pg.timerDering = setTimeout(() => {
                if (pg && !pg.tersambungAt) {
                    beriPesan(label(pg.lawan) + ' tidak menjawab.');
                    akhiri('tak_terjawab');
                }
            }, (CFG.deringDetik + 5) * 1000);
        }

        async function mulaiSebagaiPemanggil() {
            clearTimeout(pg.timerDering);
            ubahPanggilan({ status: 'menyambung' });
            const pc = buatPc();
            pg.stream.getTracks().forEach(t => pc.addTrack(t, pg.stream));
            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                kirimPg(pg, { t: 'offer', sdp: { type: offer.type, sdp: offer.sdp } });
            } catch (e) { gagal(e); }
        }

        // ── masuk: dipanggil ──
        async function masukPanggilan(m) {
            if (pg) { kirim({ t: 'tolak', ke: m.dari, id: m.id, alasan: 'sibuk' }); return; }
            pg = baru('penerima', m.dari);
            pg.id = m.id;
            pg.lawanDev = m.dev;
            umumkanHadir();

            if (keadaan.dnd) {
                ubahPanggilan({ status: 'berdering' });
                kirimPg(pg, { t: 'berdering' });
                deringMulai();
                notifikasi(label(m.dari) + ' memanggil');
                pg.timerDering = setTimeout(() => { if (pg && !pg.tersambungAt) akhiri('tak_terjawab', true); },
                    CFG.deringDetik * 1000);
                return;
            }
            await angkat(true);
        }

        async function angkat(otomatis) {
            const p = pg;
            if (!p || p.peran !== 'penerima' || p.diangkat) return;
            p.diangkat = true;
            clearTimeout(p.timerDering);
            deringBerhenti();
            ubahPanggilan({ status: 'menyambung' });
            if (otomatis) {
                // Nada dulu, BARU mikrofon dibuka — orang di ruangan tahu
                // mereka akan terdengar.
                dingDong();
                await new Promise(r => setTimeout(r, CFG.nadaMs));
                if (pg !== p) return; // pemanggil membatalkan
            }
            let stream;
            try {
                stream = await ambilMic();
            } catch (e) {
                if (pg !== p) return;
                kirimPg(p, { t: 'tolak', alasan: 'mic' });
                gagal(e, 'Mikrofon stasiun ini tidak bisa dibuka — klik "Aktifkan mikrofon" di panel intercom.', true);
                return;
            }
            if (pg !== p) { stream.getTracks().forEach(t => t.stop()); return; }
            p.stream = stream;
            kirimPg(p, { t: 'terima' });
        }

        async function jawabOffer(sdp) {
            if (!pg.stream) return;
            const pc = buatPc();
            pg.stream.getTracks().forEach(t => pc.addTrack(t, pg.stream));
            try {
                await pc.setRemoteDescription(sdp);
                tuangIceTertunda();
                const ans = await pc.createAnswer();
                await pc.setLocalDescription(ans);
                kirimPg(pg, { t: 'answer', sdp: { type: ans.type, sdp: ans.sdp } });
            } catch (e) { gagal(e); }
        }

        function tolak() {
            if (!pg || pg.peran !== 'penerima') return;
            kirimPg(pg, { t: 'tolak', alasan: 'ditolak' });
            akhiri('ditolak', true);
        }

        // ── WebRTC ──
        function baru(peran, lawan) {
            return {
                id: acak(), peran, lawan, lawanDev: null,
                pc: null, stream: null, iceTertunda: [], iceKeluar: [], iceTimer: null,
                mulai: Date.now(), tersambungAt: null, timers: [],
            };
        }

        function buatPc() {
            const pc = new RTCPeerConnection({ iceServers: CFG.iceServers });
            pg.pc = pc;
            // ICE dikumpulkan lalu dikirim per 250 ms dalam satu pesan, bukan
            // satu pesan per kandidat.
            pc.onicecandidate = (e) => {
                if (!pg || pg.pc !== pc) return;
                if (e.candidate) pg.iceKeluar.push(e.candidate.toJSON());
                if (!pg.iceTimer) {
                    pg.iceTimer = setTimeout(() => {
                        if (!pg) return;
                        pg.iceTimer = null;
                        if (pg.iceKeluar.length) {
                            kirimPg(pg, { t: 'ice', c: pg.iceKeluar.splice(0) });
                        }
                    }, 250);
                }
            };
            pc.ontrack = (e) => putarSuara(e.streams[0] || new MediaStream([e.track]));
            pc.onconnectionstatechange = () => {
                if (!pg || pg.pc !== pc) return;
                const s = pc.connectionState;
                if (s === 'connected' && !pg.tersambungAt) {
                    pg.tersambungAt = Date.now();
                    ubahPanggilan({ status: 'tersambung', tersambungAt: pg.tersambungAt });
                    mulaiPantauHening();
                    pg.timers.push(setTimeout(() => {
                        beriPesan('Panggilan ditutup otomatis (batas ' + Math.round(CFG.maksDetik / 60) + ' menit).');
                        akhiri('tersambung');
                    }, CFG.maksDetik * 1000));
                } else if (s === 'failed') {
                    gagal(null, 'Suara tidak bisa tersambung. Pastikan kedua perangkat di Wi-Fi kantor '
                        + 'dan fitur "AP/client isolation" di router mati.');
                } else if (s === 'disconnected') {
                    pg.timers.push(setTimeout(() => {
                        if (pg && pg.pc === pc && pc.connectionState !== 'connected') akhiri('tersambung');
                    }, 5000));
                }
            };
            return pc;
        }

        function tuangIceTertunda() {
            if (!pg || !pg.pc) return;
            pg.iceTertunda.splice(0).forEach(c => pg.pc.addIceCandidate(c).catch(() => {}));
        }

        function ambilMic() {
            return navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                video: false,
            }).then(s => { setKeadaan({ mic: 'granted' }); return s; });
        }

        let penguat = null;     // {sumber, gain, kompresor} selama panggilan
        function putarSuara(stream) {
            if (!audioEl) {
                audioEl = document.createElement('audio');
                audioEl.autoplay = true;
                audioEl.setAttribute('playsinline', '');
                document.body.appendChild(audioEl);
            }
            audioEl.srcObject = stream;
            if (pg) pg.streamLawan = stream;
            pasangPenguat(stream);
            const p = audioEl.play();
            if (p && p.catch) p.then(() => setKeadaan({ perluKetuk: false }))
                .catch(() => setKeadaan({ perluKetuk: true }));
        }

        // Suara lawan: sumber → gain (volume) → kompresor (cegah pecah) → speaker.
        // <audio> tetap diputar tapi dibisukan — Chrome baru mengalirkan stream
        // WebRTC ke Web Audio bila stream itu juga terpasang di elemen media.
        function pasangPenguat(stream) {
            lepasPenguat();
            const c = ctx();
            if (!c || c.state !== 'running') { audioEl.muted = false; return; }
            try {
                const sumber = c.createMediaStreamSource(stream);
                const gain = c.createGain();
                gain.gain.value = keadaan.volume;
                const kompresor = c.createDynamicsCompressor();
                kompresor.threshold.value = -12;
                kompresor.knee.value = 6;
                kompresor.ratio.value = 8;
                sumber.connect(gain).connect(kompresor).connect(c.destination);
                penguat = { sumber, gain, kompresor };
                audioEl.muted = true;
            } catch (e) {
                audioEl.muted = false;
            }
        }
        function lepasPenguat() {
            if (!penguat) return;
            try { penguat.sumber.disconnect(); penguat.gain.disconnect(); penguat.kompresor.disconnect(); } catch (e) {}
            penguat = null;
        }
        function aturVolume(v) {
            v = Math.min(4, Math.max(1, parseFloat(v) || 1));
            tulis(SIMPAN.volume, String(v));
            if (penguat) penguat.gain.gain.value = v;
            setKeadaan({ volume: v });
        }

        function bunyikanUlang() {
            ctx();
            // Ketukan juga membuka AudioContext → sekarang penguat bisa dipasang.
            if (audioEl && audioEl.srcObject && !penguat) pasangPenguat(audioEl.srcObject);
            if (audioEl) audioEl.play().then(() => setKeadaan({ perluKetuk: false })).catch(() => {});
        }

        // Tutup otomatis bila kedua pihak diam selama CFG.heningDetik.
        function mulaiPantauHening() {
            const c = ctx();
            if (!c || c.state !== 'running' || !pg) return;
            const ukur = (stream) => {
                try {
                    const a = c.createAnalyser();
                    a.fftSize = 512;
                    c.createMediaStreamSource(stream).connect(a);
                    return a;
                } catch (e) { return null; }
            };
            const analis = [pg.stream, pg.streamLawan].filter(Boolean).map(ukur).filter(Boolean);
            if (!analis.length) return;
            const buf = new Float32Array(512);
            let terakhirSuara = Date.now();
            pg.timers.push(setInterval(() => {
                if (!pg) return;
                const keras = analis.some(a => {
                    a.getFloatTimeDomainData(buf);
                    let jml = 0;
                    for (let i = 0; i < buf.length; i++) jml += buf[i] * buf[i];
                    return Math.sqrt(jml / buf.length) > 0.015;
                });
                if (keras) terakhirSuara = Date.now();
                else if (Date.now() - terakhirSuara > CFG.heningDetik * 1000) {
                    beriPesan('Panggilan ditutup otomatis karena hening ' + CFG.heningDetik + ' detik.');
                    akhiri('tersambung');
                }
            }, 500));
        }

        // ── mengakhiri ──
        function akhiri(statusLog, dariLawan) {
            if (!pg) return;
            const p = pg;
            pg = null;
            if (!dariLawan) kirimPg(p, { t: 'tutup' });
            clearTimeout(p.timerDering);
            clearTimeout(p.iceTimer);
            p.timers.forEach(t => { clearTimeout(t); clearInterval(t); });
            deringBerhenti();
            if (p.stream) p.stream.getTracks().forEach(t => t.stop());
            if (p.pc) { try { p.pc.close(); } catch (e) {} }
            lepasPenguat();
            if (audioEl) audioEl.srcObject = null;
            if (p.tersambungAt || p.diangkat || p.peran === 'pemanggil') nadaTutup();
            if (p.peran === 'pemanggil') {
                const lama = p.tersambungAt ? Date.now() - p.tersambungAt : 0;
                catat(p.lawan, p.tersambungAt ? 'tersambung' : statusLog, Math.round(lama / 1000));
            }
            setKeadaan({ panggilan: null, perluKetuk: false });
            umumkanHadir();
        }

        function gagal(e, teks, sudahDikabari) {
            if (e) console.warn('[intercom]', e);
            beriPesan(teks || 'Panggilan gagal.', 10000);
            akhiri('gagal', sudahDikabari);
        }

        function ubahPanggilan(ubah) {
            const lama = keadaan.panggilan || {};
            const dasar = pg ? { peran: pg.peran, lawan: pg.lawan, mulai: pg.mulai, bisu: !!lama.bisu } : {};
            setKeadaan({ panggilan: Object.assign({}, lama, dasar, ubah) });
        }

        function bisu() {
            if (!pg || !pg.stream) return;
            const sekarang = !(keadaan.panggilan && keadaan.panggilan.bisu);
            pg.stream.getAudioTracks().forEach(t => { t.enabled = !sekarang; });
            ubahPanggilan({ bisu: sekarang });
        }

        function aturDnd(v) {
            tulis(SIMPAN.dnd, v ? '1' : '0');
            setKeadaan({ dnd: !!v });
            umumkanHadir();
        }

        // Klik "Aktifkan": buka kunci suara + minta izin mikrofon sekali,
        // supaya panggilan masuk nanti bisa langsung tersambung tanpa dialog.
        // Tes mikrofon 8 detik dengan indikator level. Lawan bicara yang
        // "tidak terdengar" hampir selalu mic yang salah pilih / tak tercolok
        // di perangkatnya — ini supaya tiap stasiun bisa memeriksa sendiri.
        async function tesMic() {
            if (pg || keadaan.tesMic) return;
            ctx();
            let s;
            try { s = await ambilMic(); } catch (e) {
                return beriPesan('Mikrofon tidak bisa dibuka: ' + (e.message || e.name), 10000);
            }
            const track = s.getAudioTracks()[0];
            setKeadaan({ tesMic: { label: track ? track.label : '' } });
            const c = ctx();
            let puncak = 0, timer = null;
            if (c) {
                const a = c.createAnalyser();
                a.fftSize = 512;
                c.createMediaStreamSource(s).connect(a);
                const buf = new Float32Array(512);
                timer = setInterval(() => {
                    a.getFloatTimeDomainData(buf);
                    let jml = 0;
                    for (let i = 0; i < buf.length; i++) jml += buf[i] * buf[i];
                    const rms = Math.sqrt(jml / buf.length);
                    puncak = Math.max(puncak, rms);
                    const lebar = Math.min(100, Math.round(rms * 400)) + '%';
                    document.querySelectorAll('.ic-meter i').forEach(el => { el.style.width = lebar; });
                }, 100);
            }
            setTimeout(() => {
                clearInterval(timer);
                s.getTracks().forEach(t => t.stop());
                setKeadaan({ tesMic: null });
                if (!c || c.state !== 'running') {
                    beriPesan('Klik sekali di halaman lalu ulangi tes (suara halaman belum aktif).', 8000);
                } else if (puncak < 0.02) {
                    beriPesan('⚠ Mikrofon "' + (track ? track.label : '') + '" TIDAK menangkap suara. Pastikan mic '
                        + 'tercolok & tidak di-mute di Windows (Pengaturan → Suara → Input), atau pilih mic lain '
                        + 'lewat ikon gembok di bilah alamat Chrome.', 15000);
                } else {
                    beriPesan('✅ Mikrofon berfungsi — suara tertangkap dengan baik.', 6000);
                }
            }, 8000);
        }

        async function aktifkan() {
            ctx();
            try {
                const s = await ambilMic();
                s.getTracks().forEach(t => t.stop());
                beriPesan('Intercom aktif. Stasiun ini siap menerima panggilan.', 4000);
            } catch (e) {
                setKeadaan({ mic: 'denied' });
                beriPesan('Izin mikrofon ditolak. Buka pengaturan situs di peramban lalu izinkan mikrofon.', 10000);
            }
        }

        function cekIzinMic() {
            if (!navigator.permissions || !navigator.permissions.query) return;
            navigator.permissions.query({ name: 'microphone' }).then(st => {
                setKeadaan({ mic: st.state });
                st.onchange = () => setKeadaan({ mic: st.state });
            }).catch(() => {});
        }

        function catat(ke, status, detik) {
            fetch(CFG.logUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CFG.csrf },
                body: JSON.stringify({ dari: keadaan.stasiun, ke, status, durasi_detik: detik }),
                keepalive: true,
            }).catch(() => {});
        }

        function notifikasi(teks) {
            try {
                if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
                    new Notification('Intercom', { body: teks, tag: 'intercom' });
                }
            } catch (e) {}
        }

        // Layar perangkat stasiun jangan mati (tablet/HP).
        let kunciLayar = null;
        function mintaKunciLayar() {
            if (document.hidden || kunciLayar) return;
            navigator.wakeLock.request('screen').then(l => {
                kunciLayar = l;
                l.addEventListener('release', () => { kunciLayar = null; });
            }).catch(() => {});
        }
        function wakeLock() {
            if (!('wakeLock' in navigator)) return;
            mintaKunciLayar();
            document.addEventListener('visibilitychange', mintaKunciLayar);
        }
        function lepasWakeLock() {
            document.removeEventListener('visibilitychange', mintaKunciLayar);
            if (kunciLayar) { kunciLayar.release().catch(() => {}); kunciLayar = null; }
        }

        function jalankan(aksi, arg) {
            switch (aksi) {
                case 'panggil': return panggil(arg);
                case 'tutup': return akhiri('tak_terjawab');
                case 'angkat': return angkat(false);
                case 'tolak': return tolak();
                case 'bisu': return bisu();
                case 'dnd': return aturDnd(arg);
                case 'aktifkan': return aktifkan();
                case 'tesmic': return tesMic();
                case 'volume': return aturVolume(arg);
                case 'ketuk': return bunyikanUlang();
            }
        }

        // Untuk menelusuri keluhan "tidak ada suara": dibaca dari konsol
        // lewat `await Intercom.diagnosa()` saat panggilan berjalan.
        async function diagnosa() {
            const d = {
                audioEl: audioEl ? { paused: audioEl.paused, muted: audioEl.muted, volume: audioEl.volume,
                    adaStream: !!audioEl.srcObject } : null,
                audioCtx: audioCtx ? audioCtx.state : null,
            };
            if (!pg) return Object.assign(d, { panggilan: null });
            d.peran = pg.peran;
            d.lawan = pg.lawan;
            d.micLokal = pg.stream ? pg.stream.getAudioTracks().map(t => ({
                label: t.label, enabled: t.enabled, muted: t.muted, state: t.readyState })) : null;
            d.trackLawan = pg.streamLawan ? pg.streamLawan.getAudioTracks().map(t => ({
                enabled: t.enabled, muted: t.muted, state: t.readyState })) : null;
            if (pg.pc) {
                d.koneksi = pg.pc.connectionState;
                d.ice = pg.pc.iceConnectionState;
                const st = await pg.pc.getStats();
                st.forEach(r => {
                    if (r.type === 'inbound-rtp' && r.kind === 'audio') {
                        d.masuk = { bytes: r.bytesReceived, paket: r.packetsReceived, hilang: r.packetsLost,
                            level: r.audioLevel, energi: r.totalAudioEnergy };
                    } else if (r.type === 'outbound-rtp' && r.kind === 'audio') {
                        d.keluar = { bytes: r.bytesSent, paket: r.packetsSent };
                    } else if (r.type === 'media-source' && r.kind === 'audio') {
                        d.levelMicLokal = r.audioLevel;
                    } else if (r.type === 'candidate-pair' && r.nominated && r.state === 'succeeded') {
                        d.jalur = r.localCandidateId + ' ↔ ' + r.remoteCandidateId;
                    }
                });
            }
            return d;
        }

        return { mulai, berhenti, jalankan, gantiStasiun, diagnosa };
    }

    // ───────────────────────── RENDER ─────────────────────────
    function online(k) { return !!keadaan.hadir[k]; }

    function htmlDaftar(besar) {
        const saya = keadaan.stasiun;
        return Object.keys(STASIUN).filter(k => k !== saya).map(k => {
            const h = keadaan.hadir[k];
            const st = !h ? 'offline' : (h.sibuk ? 'sibuk' : (h.dnd ? 'jangan ganggu' : 'online'));
            const kls = !h ? 'off' : (h.sibuk ? 'sibuk' : (h.dnd ? 'dnd' : 'on'));
            return '<button type="button" class="ic-stasiun ' + (besar ? 'ic-besar ' : '') + 'ic-' + kls + '"'
                + ' data-ic-aksi="panggil" data-ic-arg="' + esc(k) + '"'
                + (h && !keadaan.panggilan ? '' : ' disabled') + '>'
                + '<span class="ic-ikon">' + ikon(k) + '</span>'
                + '<span class="ic-nama">' + esc(label(k)) + '</span>'
                + '<span class="ic-st"><i></i>' + st + '</span>'
                + '</button>';
        }).join('');
    }

    function htmlVolume() {
        const v = keadaan.volume || 1;
        return '<label class="ic-vol" title="Volume suara lawan bicara">🔊 '
            + '<input type="range" min="1" max="4" step="0.25" value="' + v + '" data-ic-aksi="volume">'
            + '<span data-ic-vol-teks>' + Math.round(v * 100) + '%</span></label>';
    }

    function htmlPilihStasiun() {
        return '<div class="ic-pilih">'
            + '<p>Perangkat ini stasiun divisi apa?</p>'
            + Object.keys(STASIUN).map(k =>
                '<button type="button" class="ic-pil" data-ic-aksi="stasiun" data-ic-arg="' + esc(k) + '">'
                + ikon(k) + ' ' + esc(label(k)) + '</button>').join('')
            + '<small>Satu perangkat = satu divisi. Bisa diubah kapan saja.</small>'
            + '</div>';
    }

    function htmlStatusBawah() {
        const k = keadaan.stasiun;
        let h = '<div class="ic-bawah">';
        h += '<div>Stasiun ini: <b>' + ikon(k) + ' ' + esc(label(k)) + '</b> '
            + '<a href="#" data-ic-aksi="stasiun" data-ic-arg="">ubah</a></div>';
        h += '<label class="ic-dnd"><input type="checkbox" data-ic-aksi="dnd"' + (keadaan.dnd ? ' checked' : '') + '>'
            + ' Jangan Ganggu <small>(berdering, angkat manual)</small></label>';
        h += htmlVolume();
        if (!keadaan.terhubung) h += '<div class="ic-warn">⚠ Belum tersambung ke server intercom…</div>';
        if (keadaan.konflik) h += '<div class="ic-warn">⚠ Ada perangkat lain yang juga memakai stasiun '
            + esc(label(k)) + '. Ubah salah satunya.</div>';
        if (keadaan.mic !== 'granted') {
            h += '<button type="button" class="ic-aktif" data-ic-aksi="aktifkan">🎙️ Aktifkan mikrofon</button>';
        } else if (keadaan.tesMic) {
            h += '<div class="ic-tes"><div>🎙️ ' + esc(keadaan.tesMic.label || 'Mikrofon') + ' — bicaralah…</div>'
                + '<div class="ic-meter"><i></i></div></div>';
        } else if (!keadaan.panggilan) {
            h += '<button type="button" class="ic-tesb" data-ic-aksi="tesmic">🎚️ Tes mikrofon</button>';
        }
        h += '</div>';
        return h;
    }

    // Selama slider volume digeser, jangan render ulang (innerHTML baru akan
    // mencabut slider dari bawah jari). Render menyusul saat dilepas.
    let sedangGeser = false;
    document.addEventListener('pointerdown', (e) => {
        if (e.target.closest && e.target.closest('input[data-ic-aksi="volume"]')) sedangGeser = true;
    }, true);
    ['pointerup', 'pointercancel'].forEach(ev => document.addEventListener(ev, () => {
        if (sedangGeser) { sedangGeser = false; render(); }
    }, true));

    function render() {
        if (sedangGeser) return;
        // Tombol topbar
        document.querySelectorAll('.ic-tombol').forEach(b => {
            const titik = b.querySelector('.ic-titik');
            if (titik) {
                titik.className = 'ic-titik ' + (!keadaan.stasiun ? 'abu'
                    : (keadaan.panggilan ? 'merah' : (keadaan.terhubung ? 'hijau' : 'kuning')));
            }
            b.title = !keadaan.stasiun ? 'Intercom — atur stasiun'
                : 'Intercom (' + label(keadaan.stasiun) + ')' + (keadaan.terhubung ? '' : ' — belum tersambung');
        });

        // Panel dropdown + layar stasiun
        document.querySelectorAll('[data-ic-isi]').forEach(el => {
            const besar = el.dataset.icIsi === 'besar';
            if (!keadaan.stasiun) { el.innerHTML = htmlPilihStasiun(); return; }
            el.innerHTML = '<div class="ic-daftar' + (besar ? ' ic-daftar-besar' : '') + '">' + htmlDaftar(besar) + '</div>'
                + htmlStatusBawah();
        });

        renderKartu();
    }

    let jamTimer = null;
    function renderKartu() {
        let kartu = document.getElementById('icKartu');
        if (!kartu) {
            kartu = document.createElement('div');
            kartu.id = 'icKartu';
            document.body.appendChild(kartu);
        }
        const p = keadaan.panggilan;
        let h = '';
        if (p) {
            const nama = ikon(p.lawan) + ' ' + esc(label(p.lawan));
            let judul, tombol = '';
            if (p.status === 'tersambung') {
                judul = '<span class="ic-live">● TERSAMBUNG</span> ' + nama
                    + ' <span class="ic-jam" data-ic-jam="' + p.tersambungAt + '">' + durasi(Date.now() - p.tersambungAt) + '</span>';
                tombol = htmlVolume() + '<button type="button" data-ic-aksi="bisu" class="ic-b ' + (p.bisu ? 'ic-b-on' : '') + '">'
                    + (p.bisu ? '🔇 Bisu' : '🎙️ Bisu') + '</button>';
            } else if (p.peran === 'penerima' && p.status === 'berdering') {
                judul = '📞 ' + nama + ' memanggil…';
                tombol = '<button type="button" data-ic-aksi="angkat" class="ic-b ic-b-hijau">Angkat</button>';
            } else if (p.peran === 'penerima') {
                judul = '📞 ' + nama + ' memanggil — menyambung…';
            } else {
                judul = (p.status === 'berdering' ? '🔔 Menunggu ' + nama + ' mengangkat…' : '📞 Memanggil ' + nama + '…');
            }
            tombol += '<button type="button" data-ic-aksi="' + (p.peran === 'penerima' && p.status === 'berdering' ? 'tolak' : 'tutup')
                + '" class="ic-b ic-b-merah">' + (p.peran === 'penerima' && p.status === 'berdering' ? 'Tolak' : 'Tutup') + '</button>';
            h += '<div class="ic-kartu ' + (p.status === 'tersambung' ? 'ic-kartu-live' : '') + '">'
                + '<div class="ic-judul">' + judul + '</div><div class="ic-aksi">' + tombol + '</div>';
            if (keadaan.perluKetuk && sayaMesin) {
                h += '<button type="button" data-ic-aksi="ketuk" class="ic-ketuk">🔈 Ketuk di sini untuk mendengar suara</button>';
            }
            if (!sayaMesin) h += '<small class="ic-catatan">Suara diputar di tab ERP lain yang terbuka.</small>';
            h += '</div>';
        }
        if (keadaan.pesan) h += '<div class="ic-pesan">' + esc(keadaan.pesan) + '</div>';
        kartu.innerHTML = h;

        clearInterval(jamTimer);
        if (p && p.status === 'tersambung') {
            jamTimer = setInterval(() => {
                document.querySelectorAll('[data-ic-jam]').forEach(j => {
                    j.textContent = durasi(Date.now() - parseInt(j.dataset.icJam, 10));
                });
            }, 1000);
        }
    }

    // Satu penangan klik untuk semua elemen intercom (isinya dirender ulang).
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-ic-aksi]');
        if (!t || t.tagName === 'INPUT') return;
        e.preventDefault();
        perintah(t.dataset.icAksi, t.dataset.icArg);
    });
    document.addEventListener('change', (e) => {
        const t = e.target.closest('input[data-ic-aksi="dnd"]');
        if (t) perintah('dnd', t.checked);
    });
    document.addEventListener('input', (e) => {
        const t = e.target.closest('input[data-ic-aksi="volume"]');
        if (!t) return;
        document.querySelectorAll('[data-ic-vol-teks]').forEach(el => { el.textContent = Math.round(t.value * 100) + '%'; });
        perintah('volume', t.value);
    });

    // Dropdown topbar
    document.addEventListener('click', (e) => {
        const tombol = e.target.closest('.ic-tombol');
        document.querySelectorAll('.ic-wadah').forEach(w => {
            if (tombol && w.contains(tombol)) w.classList.toggle('buka');
            else if (e.target.isConnected && !w.contains(e.target)) w.classList.remove('buka');
        });
    });

    // ───────────────────────── mulai ─────────────────────────
    window.Intercom = {
        perintah,
        keadaan: () => keadaan,
        diagnosa: () => (sayaMesin && mesin ? mesin.diagnosa() : Promise.resolve({ bukanTabMesin: true })),
    };
    render();
    if (tabKanal) tabKanal.postMessage({ jenis: 'minta-keadaan' });
    mintaKunci();
})();
