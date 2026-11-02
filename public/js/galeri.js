/*
 * Galeri PWA (/cs/galeri) — komponen Alpine `galeriApp`.
 *
 * Unggahan dipotong-potong (ukuran dari server, lihat GaleriController::
 * ukuranPotongan) dan dikirim BERURUTAN: lewat Cloudflare satu request dibatasi
 * 100 MB, dan video beberapa ratus MB tak boleh gagal total hanya karena sinyal
 * putus sedetik. Server menjawab 409 + `diterima` bila potongan tidak nyambung,
 * jadi pengiriman ulang selalu melanjutkan, tidak mengulang dari nol.
 */
(function () {
    var CSRF = function () {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    };

    function tunggu(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function idBaru() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function cap(d) {
        var p = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '_' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
    }

    /* Hasil kamera di Android sering bernama "image.jpg" / angka acak. Diberi
       nama bertanggal seperti kamera HP supaya urut & mudah dicari di Explorer. */
    function namaKamera(file, jenis, urut) {
        var ext = (file.name.split('.').pop() || (jenis === 'video' ? 'mp4' : 'jpg')).toLowerCase();
        if (ext.length > 5) ext = jenis === 'video' ? 'mp4' : 'jpg';
        return (jenis === 'video' ? 'VID_' : 'IMG_') + cap(new Date()) + (urut ? '_' + urut : '') + '.' + ext;
    }

    window.formatUkuran = function (b) {
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return Math.round(b / 1024) + ' KB';
        return b + ' B';
    };

    window.galeriApp = function (cfg) {
        return {
            cfg: cfg,
            antrian: [],
            sibuk: false,
            lihat: null,          // berkas yang sedang dibuka di penampil
            menu: null,           // folder yang menu ⋯-nya terbuka
            formNama: null,       // {judul, aksi, p, nama}
            pilih: null,          // pemilih folder tujuan: {p, path, remah, folder, muat}
            kunciLayar: null,

            init() {
                var self = this;
                window.addEventListener('beforeunload', function (e) {
                    if (self.sibuk) { e.preventDefault(); e.returnValue = ''; }
                });
            },

            // ───── Unggah ─────
            pilihBerkas(ev, sumber) {
                var files = Array.prototype.slice.call(ev.target.files || []);
                ev.target.value = '';
                var self = this;
                files.forEach(function (f, i) {
                    var jenis = (f.type || '').indexOf('video/') === 0 ? 'video' : 'gambar';
                    var nama = sumber === 'kamera' ? namaKamera(f, jenis, files.length > 1 ? i + 1 : 0) : f.name;
                    if (f.size > self.cfg.maksMb * 1048576) {
                        self.antrian.push({ id: idBaru(), file: f, nama: nama, persen: 0, status: 'gagal', pesan: 'Lebih dari ' + self.cfg.maksMb + ' MB' });
                        return;
                    }
                    self.antrian.push({ id: idBaru(), file: f, nama: nama, persen: 0, status: 'antre', pesan: '' });
                });
                this.jalankan();
            },

            async jalankan() {
                if (this.sibuk) return;
                this.sibuk = true;
                await this.tahanLayar();
                var adaBerhasil = false;
                for (var i = 0; i < this.antrian.length; i++) {
                    var it = this.antrian[i];
                    if (it.status !== 'antre') continue;
                    it.status = 'jalan';
                    try {
                        await this.kirim(it);
                        it.status = 'selesai';
                        it.persen = 100;
                        adaBerhasil = true;
                    } catch (e) {
                        it.status = 'gagal';
                        it.pesan = e.message || 'Gagal';
                    }
                }
                this.sibuk = false;
                this.lepasLayar();
                var semuaBeres = this.antrian.every(function (x) { return x.status === 'selesai'; });
                // Semua masuk → muat ulang supaya fotonya tampil di daftar.
                // Ada yang gagal → biarkan daftar antrian kelihatan dulu.
                if (adaBerhasil && semuaBeres) window.location.reload();
            },

            async kirim(it) {
                var f = it.file, ukuran = this.cfg.potongan, offset = 0, coba = 0;
                while (offset < f.size || (f.size === 0 && offset === 0)) {
                    var fd = new FormData();
                    fd.append('id', it.id);
                    fd.append('offset', offset);
                    fd.append('total', f.size);
                    fd.append('nama', it.nama);
                    fd.append('folder', this.cfg.folder);
                    if (this.cfg.so) fd.append('so', this.cfg.so);
                    fd.append('potongan', f.slice(offset, offset + ukuran), 'potongan');

                    var res;
                    try {
                        res = await fetch(this.cfg.urlUnggah, {
                            method: 'POST', body: fd, credentials: 'same-origin',
                            headers: { 'X-CSRF-TOKEN': CSRF(), 'Accept': 'application/json' },
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
                    if (res.status === 419) throw new Error('Sesi habis — muat ulang halaman');
                    if (res.status === 413) throw new Error('Potongan ditolak server (terlalu besar)');
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
                    it.persen = f.size ? Math.floor(offset * 100 / f.size) : 100;
                    if (data.selesai) return;
                }
            },

            sisaAntre() {
                return this.antrian.filter(function (x) { return x.status === 'antre' || x.status === 'jalan'; }).length;
            },

            ulangiGagal() {
                this.antrian.forEach(function (x) { if (x.status === 'gagal' && x.file) { x.status = 'antre'; x.pesan = ''; x.persen = 0; } });
                this.jalankan();
            },

            /* Layar mati = tab dibekukan = unggahan berhenti. */
            async tahanLayar() {
                try { if (navigator.wakeLock) this.kunciLayar = await navigator.wakeLock.request('screen'); } catch (e) {}
            },
            lepasLayar() {
                try { if (this.kunciLayar) this.kunciLayar.release(); } catch (e) {}
                this.kunciLayar = null;
            },

            // ───── Folder tujuan (pindah) ─────
            async bukaPilih(p) {
                this.menu = null;
                this.pilih = { p: p, path: '', remah: [], folder: [], muat: true };
                await this.muatPilih('');
            },
            async muatPilih(path) {
                this.pilih.muat = true;
                try {
                    var res = await fetch(this.cfg.urlFolder + '?p=' + encodeURIComponent(path), { headers: { 'Accept': 'application/json' } });
                    var data = await res.json();
                    if (!res.ok) throw new Error(data.pesan || 'Gagal');
                    this.pilih.path = data.path;
                    this.pilih.remah = data.remah;
                    var p = this.pilih.p;
                    // Folder yang dipindah tidak bisa jadi tujuannya sendiri.
                    this.pilih.folder = data.folder.filter(function (f) { return f.path !== p; });
                } catch (e) {
                    alert(e.message);
                }
                this.pilih.muat = false;
            },
        };
    };
})();
