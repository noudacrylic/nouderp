/*
 * Tugas dari Owner — komponen Alpine `perekamTugas`: rekam layar + suara (komputer), rekam suara
 * (komputer & HP), atau pilih berkas, lalu unggah bertahap ke NAS. Lampiran yang selesai diunggah
 * ikut terkirim bersama pesan lewat input lampiran[].
 *
 * Browser HP umumnya tak punya perekam layar (getDisplayMedia), jadi tombolnya disembunyikan dan
 * rekaman layar dari fitur bawaan HP diunggah lewat tombol Lampirkan.
 */
(function () {
    function csrf() {
        var m = document.querySelector('meta[name=csrf-token]');
        return m ? m.content : '';
    }

    function tunggu(ms) {
        return new Promise(function (r) { setTimeout(r, ms); });
    }

    function idBaru() {
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function formatDurasi(detik) {
        var m = Math.floor(detik / 60), s = detik % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function formatUkuran(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    /* Format pertama yang didukung browser ini. */
    function pilihFormat(daftar) {
        if (!window.MediaRecorder) return null;
        for (var i = 0; i < daftar.length; i++) {
            if (MediaRecorder.isTypeSupported(daftar[i])) return daftar[i];
        }
        return null;
    }

    var FORMAT_LAYAR = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    var FORMAT_SUARA = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];

    function ekstensi(mime) {
        if (mime.indexOf('webm') !== -1) return 'webm';
        if (mime.indexOf('ogg') !== -1) return 'ogg';
        return mime.indexOf('audio') === 0 ? 'm4a' : 'mp4';
    }

    window.perekamTugas = function (cfg) {
        return {
            cfg: cfg,
            bisaLayar: !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && pilihFormat(FORMAT_LAYAR)),
            bisaSuara: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && pilihFormat(FORMAT_SUARA)),
            merekam: null,
            detik: 0,
            galat: '',
            peringatan: '',
            antrian: [],
            formatDurasi: formatDurasi,
            formatUkuran: formatUkuran,

            init() {
                var self = this;
                window.addEventListener('beforeunload', function (e) {
                    if (self.merekam || self.sibuk()) { e.preventDefault(); e.returnValue = ''; }
                });
            },

            sibuk() {
                return this.antrian.some(function (x) { return x.status === 'jalan' || x.status === 'antre'; });
            },

            selesaiIds() {
                return this.antrian.filter(function (x) { return x.status === 'selesai'; }).map(function (x) { return x.lampiran.id; });
            },

            /* ── Rekam layar + suara ── */
            async rekamLayar() {
                this.galat = this.peringatan = '';
                var layar, mic = null;
                try {
                    // Brief sering memperagakan aplikasi lain, jadi Seluruh layar yang disarankan browser.
                    layar = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 15, displaySurface: 'monitor' }, audio: true });
                } catch (e) {
                    if (e.name !== 'NotAllowedError') this.galat = 'Perekam layar tidak bisa dimulai: ' + e.message;
                    return;
                }
                try {
                    mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
                } catch (e) {
                    this.peringatan = 'Mikrofon tidak diizinkan: rekaman ini TANPA suara Anda.';
                }

                var permukaan = layar.getVideoTracks()[0].getSettings().displaySurface;
                if (permukaan === 'browser' || permukaan === 'window') {
                    this.peringatan = (this.peringatan ? this.peringatan + ' ' : '')
                        + 'Yang terekam hanya ' + (permukaan === 'browser' ? 'tab' : 'jendela') + ' yang Anda pilih. Untuk memperlihatkan aplikasi lain, tekan Selesai lalu rekam ulang dengan pilihan Seluruh layar.';
                }

                var konteks = new (window.AudioContext || window.webkitAudioContext)();
                var tujuan = konteks.createMediaStreamDestination();
                var ada = false;
                [layar, mic].forEach(function (s) {
                    if (s && s.getAudioTracks().length) { konteks.createMediaStreamSource(s).connect(tujuan); ada = true; }
                });
                var aliran = new MediaStream(layar.getVideoTracks().concat(ada ? tujuan.stream.getAudioTracks() : []));
                var self = this;

                this.mulai(aliran, pilihFormat(FORMAT_LAYAR), 'layar', 'Brief-layar', function () {
                    layar.getTracks().forEach(function (t) { t.stop(); });
                    if (mic) mic.getTracks().forEach(function (t) { t.stop(); });
                    konteks.close();
                });
                // "Stop sharing" dari bilah browser sama dengan menekan Selesai.
                layar.getVideoTracks()[0].addEventListener('ended', function () { self.berhenti(); });
            },

            /* ── Rekam suara ── */
            async rekamSuara() {
                this.galat = this.peringatan = '';
                var mic;
                try {
                    mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
                } catch (e) {
                    this.galat = 'Mikrofon tidak diizinkan. Izinkan di pengaturan browser, atau lampirkan rekaman dari HP.';
                    return;
                }
                this.mulai(mic, pilihFormat(FORMAT_SUARA), 'suara', 'Brief-suara', function () {
                    mic.getTracks().forEach(function (t) { t.stop(); });
                });
            },

            mulai(aliran, mime, jenis, awalan, bersihkan) {
                var self = this, potongan = [], perekam;
                try {
                    perekam = new MediaRecorder(aliran, jenis === 'layar' ? { mimeType: mime, videoBitsPerSecond: 1500000 } : { mimeType: mime });
                } catch (e) {
                    bersihkan();
                    this.galat = 'Browser ini tidak bisa merekam: ' + e.message;
                    return;
                }
                perekam.ondataavailable = function (e) { if (e.data && e.data.size) potongan.push(e.data); };
                perekam.onstop = function () {
                    clearInterval(self.pewaktu);
                    bersihkan();
                    self.merekam = null;
                    if (!potongan.length) { self.galat = 'Rekaman kosong, coba lagi.'; return; }
                    var dasar = mime.split(';')[0];
                    var berkas = new Blob(potongan, { type: dasar });
                    self.tambah(berkas, awalan + '-' + new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '') + '.' + ekstensi(dasar), jenis);
                };
                // Terkumpul tiap 5 detik: browser tak menahan seluruh rekaman di satu buffer.
                perekam.start(5000);
                this.perekam = perekam;
                this.merekam = jenis;
                this.detik = 0;
                this.pewaktu = setInterval(function () { self.detik++; }, 1000);
            },

            berhenti() {
                if (this.perekam && this.perekam.state !== 'inactive') this.perekam.stop();
            },

            /* ── Unggah ── */
            pilihBerkas(e) {
                var self = this;
                Array.prototype.forEach.call(e.target.files, function (f) { self.tambah(f, f.name, null); });
                e.target.value = '';
            },

            tambah(berkas, nama, rekaman) {
                if (berkas.size > this.cfg.maksMb * 1048576) {
                    this.galat = nama + ' melebihi batas ' + this.cfg.maksMb + ' MB.';
                    return;
                }
                this.antrian.push({ id: idBaru(), berkas: berkas, nama: nama, rekaman: rekaman, persen: 0, status: 'antre', pesan: '', lampiran: null });
                this.pompa();
            },

            async pompa() {
                if (this.memompa) return;
                this.memompa = true;
                for (var it; (it = this.antrian.find(function (x) { return x.status === 'antre'; }));) {
                    it.status = 'jalan';
                    it.pesan = '';
                    try {
                        await this.kirim(it);
                        it.status = 'selesai';
                    } catch (e) {
                        it.status = 'gagal';
                        it.pesan = e.message;
                    }
                }
                this.memompa = false;
            },

            ulangi(it) {
                it.status = 'antre';
                it.persen = 0;
                this.pompa();
            },

            buang(it) {
                this.antrian = this.antrian.filter(function (x) { return x !== it; });
            },

            async kirim(it) {
                var f = it.berkas, offset = 0, coba = 0;
                while (offset < f.size) {
                    var fd = new FormData();
                    fd.append('id', it.id);
                    fd.append('offset', offset);
                    fd.append('total', f.size);
                    fd.append('nama', it.nama);
                    if (it.rekaman) fd.append('rekaman', it.rekaman);
                    fd.append('potongan', f.slice(offset, offset + this.cfg.potongan), 'potongan');

                    var res;
                    try {
                        res = await fetch(this.cfg.urlUnggah, {
                            method: 'POST', body: fd, credentials: 'same-origin',
                            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                        });
                    } catch (e) {
                        // Sinyal putus: tunggu lalu kirim potongan yang sama lagi.
                        if (++coba > 8) throw new Error('Koneksi terputus');
                        it.pesan = 'Menyambung ulang…';
                        await tunggu(Math.min(15000, 1000 * coba * coba));
                        continue;
                    }

                    var data = {};
                    try { data = await res.json(); } catch (e) {}

                    if (res.status === 409 && typeof data.diterima === 'number') {
                        offset = data.diterima;
                        continue;
                    }
                    if (res.status === 419) throw new Error('Sesi habis, muat ulang halaman');
                    if (!res.ok) {
                        if (res.status >= 500 && ++coba <= 8) {
                            await tunggu(Math.min(15000, 1000 * coba * coba));
                            continue;
                        }
                        throw new Error(data.pesan || data.message || ('Galat ' + res.status));
                    }

                    coba = 0;
                    it.pesan = '';
                    offset = data.diterima;
                    it.persen = Math.floor(offset * 100 / f.size);
                    if (data.selesai) { it.lampiran = data.lampiran; return; }
                }
            },
        };
    };
})();
