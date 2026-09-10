{{-- Lonceng notifikasi ERP.

     Ada DI SAMPING web push, bukan menggantikannya: push butuh izin peramban
     dan sekali lewat ia hilang, sedangkan yang di sini menunggu sampai dibaca.
     Untuk operan chat itu syarat — pekerjaan yang berpindah tangan tidak boleh
     bergantung pada apakah orangnya kebetulan membuka peramban saat itu.

     Panelnya membuka SENDIRI sekali per sesi kalau ada yang belum dibaca.
     Sekali, bukan tiap halaman: ERP ini banyak berpindah layar, dan panel yang
     menyembul terus akan ditutup refleks lalu diabaikan. --}}
<div class="lonceng-erp" x-data="lonceng()" x-init="muat()" @click.outside="buka = false"
     @keydown.escape.window="buka = false">

    <button type="button" @click="buka = !buka; if (buka) muat()" class="lonceng-tombol"
            :title="jumlah ? jumlah + ' notifikasi belum dibaca' : 'Notifikasi'">
        <span>🔔</span>
        <span x-show="jumlah" x-cloak class="lonceng-badge" x-text="jumlah > 99 ? '99+' : jumlah"></span>
    </button>

    <div x-show="buka" x-cloak class="lonceng-panel"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">

        <div class="lonceng-kepala">
            <span>Notifikasi</span>
            <button type="button" x-show="jumlah" @click="bacaSemua()" class="lonceng-semua">
                Tandai semua terbaca
            </button>
        </div>

        <div class="lonceng-isi">
            <template x-for="n in daftar" :key="n.id">
                <a :href="n.url || '#'" @click="baca(n, $event)"
                   class="lonceng-item" :class="{ 'belum': !n.dibaca }">
                    <div class="lonceng-judul" x-text="n.judul"></div>
                    <div class="lonceng-teks" x-text="n.isi"></div>
                    <div class="lonceng-waktu" x-text="n.waktu"></div>
                </a>
            </template>

            <p x-show="!daftar.length" class="lonceng-kosong">Belum ada notifikasi.</p>
        </div>
    </div>
</div>

<style>
    .lonceng-erp { position: relative; margin-left: auto; flex-shrink: 0; }
    .lonceng-tombol {
        position: relative; display: flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; border-radius: 9px; border: 1px solid #e5e7eb;
        background: #fff; cursor: pointer; font-size: 15px; line-height: 1;
    }
    .lonceng-tombol:hover { background: #f9fafb; }
    .lonceng-badge {
        position: absolute; top: -5px; right: -5px; min-width: 17px; height: 17px;
        padding: 0 4px; border-radius: 9px; background: #dc2626; color: #fff;
        font-size: 10px; font-weight: 700; display: flex; align-items: center;
        justify-content: center; border: 2px solid #fff;
    }
    .lonceng-panel {
        position: absolute; top: 42px; right: 0; z-index: 60; width: 340px;
        background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,.10); overflow: hidden;
    }
    .lonceng-kepala {
        display: flex; align-items: center; justify-content: space-between;
        padding: 9px 12px; border-bottom: 1px solid #f3f4f6;
        font-size: 12px; font-weight: 700; color: #1f2937; background: #f9fafb;
    }
    .lonceng-semua { border: 0; background: none; color: #047857; font-size: 11px; cursor: pointer; }
    .lonceng-semua:hover { text-decoration: underline; }
    .lonceng-isi { max-height: 60vh; overflow-y: auto; }
    .lonceng-item {
        display: block; padding: 9px 12px; border-bottom: 1px solid #f3f4f6;
        text-decoration: none; color: inherit;
    }
    .lonceng-item:last-child { border-bottom: 0; }
    .lonceng-item:hover { background: #f9fafb; }
    /* Belum dibaca ditandai garis kiri + latar tipis, bukan huruf tebal saja:
       di daftar pendek, tebal-vs-tidak nyaris tak terbaca sekilas. */
    .lonceng-item.belum { background: #ecfdf5; box-shadow: inset 3px 0 0 #059669; }
    .lonceng-judul { font-size: 12.5px; font-weight: 600; color: #111827; }
    .lonceng-teks {
        font-size: 12px; color: #4b5563; margin-top: 1px;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .lonceng-waktu { font-size: 10.5px; color: #9ca3af; margin-top: 2px; }
    .lonceng-kosong { padding: 22px 12px; text-align: center; font-size: 12px; color: #9ca3af; margin: 0; }

    /* Bilah khusus lonceng untuk halaman tanpa tab modul (mis. Dashboard) —
       tanpa kartu, supaya tidak terbaca sebagai bilah kosong. */
    .lonceng-bar { display: flex; justify-content: flex-end; margin: -0.5rem -0.5rem 0.75rem -0.5rem; }
</style>

<script>
function lonceng() {
    return {
        buka: false,
        jumlah: 0,
        daftar: [],
        URL: {
            daftar:     @json(route('notifikasi.index')),
            bacaSemua:  @json(route('notifikasi.baca-semua')),
            // '0' diganti id sungguhan saat dipakai — route() butuh parameter.
            baca:       @json(route('notifikasi.baca', ['notifikasi' => 0])),
        },
        CSRF: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),

        async muat() {
            try {
                const r = await fetch(this.URL.daftar, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) return;

                const d = await r.json();
                this.jumlah = d.belum_dibaca ?? 0;
                this.daftar = d.daftar ?? [];

                this.bukaSekali();
            } catch (e) {
                /* Jaringan bermasalah: loncengnya diam saja, tidak menampilkan galat.
                   Tidak ada yang bisa dilakukan pengguna soal itu. */
            }
        },

        /*
         * Sekali per sesi peramban, bukan per halaman. sessionStorage-nya
         * dipilih dengan sengaja: tab baru = sesi kerja baru = layak
         * ditunjukkan lagi, sedangkan berpindah halaman di tab yang sama
         * bukan.
         */
        bukaSekali() {
            if (!this.jumlah) return;

            try {
                if (sessionStorage.getItem('lonceng-dibuka')) return;
                sessionStorage.setItem('lonceng-dibuka', '1');
            } catch (e) {
                return;   // mode privat: lewati saja, badge-nya tetap ada
            }

            this.buka = true;
        },

        async baca(n, e) {
            if (!n.url) e.preventDefault();
            if (n.dibaca) return;

            n.dibaca = true;
            this.jumlah = Math.max(0, this.jumlah - 1);

            try {
                await fetch(this.URL.baca.replace(/0\/baca$/, n.id + '/baca'), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.CSRF, 'Accept': 'application/json' },
                });
            } catch (err) { /* tautannya tetap dibuka; tandanya menyusul saat muat ulang */ }
        },

        async bacaSemua() {
            this.jumlah = 0;
            this.daftar.forEach(n => n.dibaca = true);

            try {
                await fetch(this.URL.bacaSemua, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.CSRF, 'Accept': 'application/json' },
                });
            } catch (e) { /* diabaikan; angka benar lagi saat muat ulang */ }
        },
    };
}
</script>
