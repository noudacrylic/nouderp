{{-- Penyegar kolom daftar chat — dipakai DUA aplikasi.

     ERP desktop dan PWA /cs memakai daftar yang sama (_daftar), jadi
     penyegarnya juga harus satu. Sebelumnya skrip ini tinggal di workspace
     ERP saja, dan akibatnya daftar chat di HP TIDAK pernah bergerak sendiri:
     pesanan yang sudah masuk di laptop baru muncul di aplikasi setelah CS
     membuka chat lain lalu kembali — satu-satunya cara halaman itu tergambar
     ulang.

     Parameter:
       $wadahId  id elemen pembungkus daftar (bawaan 'crm-daftar')
       $terpilih percakapan yang sedang dibuka, boleh null --}}
{{-- Kolom kiri yang hidup: menyegarkan diri DAN memanjang saat digulir.

     Paginasi dibuang dari daftar chat karena dua kebiasaan nyata: orang
     mencari chat dengan menggulir, bukan dengan mengingat nomor halaman, dan
     urutannya bergeser tiap ada pesan masuk — pada paginasi biasa satu pesan
     baru mendorong satu baris melewati batas halaman, lewat begitu saja dari
     mata orang yang sedang membaca halaman berikutnya. --}}
<script>
    (function () {
        const wadah = document.getElementById(@js($wadahId ?? 'crm-daftar'));

        if (!wadah) return;

        /* Jeda disamakan dengan polling thread (8 detik) supaya keduanya terasa
           satu irama, dan bebannya tetap satu permintaan ringan per beberapa
           detik per admin yang sedang membuka layar ini. */
        const JEDA  = 8000;
        const DASAR = '{{ route('crm.inbox.daftar-segar') }}';

        let sidik   = '';
        let pertama = true;
        let sibuk   = false;
        let pengamat = null;

        function muatKini() {
            return parseInt(wadah.querySelector('[data-daftar-kaki]')?.dataset.muat || '1', 10) || 1;
        }

        function adaLagi() {
            return wadah.querySelector('[data-daftar-kaki]')?.dataset.adaLagi === '1';
        }

        function alamat(muat, sidikKirim) {
            const q = new URLSearchParams(window.location.search);

            q.delete('page');
            q.set('muat', muat);
            q.set('sidik', sidikKirim);
            @if(($aplikasi ?? null) === 'cs')
                q.set('aplikasi', 'cs');
            @endif
            @if($terpilih)
                q.set('terpilih', '{{ $terpilih->id }}');
            @endif

            return DASAR + '?' + q.toString();
        }

        /*
         * JANGAN menukar isi daftar saat orang sedang mengetik di dalamnya.
         * Kotak pencarian hidup di kolom ini; menukar HTML-nya di tengah
         * ketikan menghapus huruf yang baru diketik berikut posisi kursornya,
         * dan yang terlihat oleh orangnya adalah layar yang "menolak diketik".
         */
        function sedangDipakai() {
            const f = document.activeElement;

            return !!f && wadah.contains(f) && (
                f.tagName === 'INPUT' || f.tagName === 'SELECT' || f.tagName === 'TEXTAREA'
            );
        }

        function tukar(html) {
            const lama   = wadah.querySelector('.overflow-y-auto');
            const posisi = lama ? lama.scrollTop : 0;

            wadah.innerHTML = html;

            const baru = wadah.querySelector('.overflow-y-auto');

            /* Gulir dipulihkan supaya daftar tidak melompat ke atas di bawah
               tangan orang yang sedang menelusurinya — baik saat disegarkan
               maupun sesudah baris baru disambung di bawah. */
            if (baru) baru.scrollTop = posisi;

            pasangPengamat();
        }

        async function ambil(muat, sidikKirim) {
            const r = await fetch(alamat(muat, sidikKirim), { headers: { 'Accept': 'application/json' } });

            return r.ok ? r.json() : null;
        }

        /* --------------------------------------------------------- memanjang */

        async function muatLagi() {
            if (sibuk || !adaLagi()) return;

            sibuk = true;

            try {
                const muat = muatKini() + 1;

                /* Sidik dikosongkan supaya server WAJIB mengirim isinya: di sini
                   kita memang meminta daftar yang LEBIH PANJANG, bukan bertanya
                   apakah ada yang berubah. */
                const d = await ambil(muat, '');

                if (!d || !d.html) return;

                sidik = d.sidik || sidik;

                tukar(d.html);

                /* URL ikut dibawa supaya muat ulang manual (F5) tidak memangkas
                   daftar kembali ke 20 baris teratas. */
                const u = new URL(window.location.href);

                u.searchParams.delete('page');
                u.searchParams.set('muat', muat);
                window.history.replaceState({}, '', u);
            } catch (e) {
                /* Jaringan putus sesaat: tombol "Muat lagi" tetap ada. */
            } finally {
                sibuk = false;
            }
        }

        function pasangPengamat() {
            pengamat?.disconnect();

            const ujung = wadah.querySelector('[data-daftar-ujung]');
            const gulir = wadah.querySelector('.overflow-y-auto');

            /* Tautannya dicegat, bukan diganti tombol: tanpa JavaScript ia
               tetap memuat ulang halaman dengan ?muat yang lebih besar. */
            wadah.querySelector('[data-muat-lagi]')?.addEventListener('click', (e) => {
                e.preventDefault();
                muatLagi();
            });

            if (!ujung || !gulir || !('IntersectionObserver' in window)) return;

            pengamat = new IntersectionObserver(
                (entri) => { if (entri.some(e => e.isIntersecting)) muatLagi(); },
                { root: gulir, rootMargin: '150px' }
            );

            pengamat.observe(ujung);
        }

        /* ------------------------------------------------------- menyegarkan */

        async function segarkan() {
            if (document.hidden || sibuk || sedangDipakai()) return;

            sibuk = true;

            try {
                const d = await ambil(muatKini(), sidik);

                if (!d) return;

                sidik = d.sidik || sidik;

                /*
                 * Putaran pertama hanya MEREKAM sidik jarinya. Tanpa ini,
                 * daftar yang baru saja digambar server ditukar dengan salinan
                 * yang isinya sama persis — menutup menu titik-tiga yang
                 * kebetulan sedang terbuka tanpa sebab yang terlihat.
                 */
                if (pertama) {
                    pertama = false;

                    return;
                }

                if (d.sama || !d.html) return;

                tukar(d.html);
            } catch (e) {
                /* Jaringan putus sesaat: diam saja, coba lagi siklus berikutnya. */
            } finally {
                sibuk = false;
            }
        }

        /* Chat yang sedang dibuka digulir ke dalam pandangan. Setelah daftar
           boleh panjang, baris yang aktif bisa berada jauh di bawah lipatan —
           dan layar yang membuka chat tanpa menunjukkan barisnya terasa seperti
           salah klik. */
        function keBarisAktif() {
            wadah.querySelector('[data-baris-aktif]')
                ?.scrollIntoView({ block: 'center' });
        }

        pasangPengamat();
        keBarisAktif();
        setInterval(segarkan, JEDA);
    })();
</script>
