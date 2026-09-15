{{-- Mode WhatsApp Web: penampung tombol "Kirim" saat kolom thread tidak ada.

     Selama Coexistence belum keluar, chat betulan dilayani dari jendela
     web.whatsapp.com di sebelah dan ERP cuma jadi alat bantu. Kolom tengah
     dilipat, dan ITU masalahnya: seluruh tombol "Kirim" di tiga tab panel
     bicara lewat peristiwa 'sisip-teks' yang SATU-SATUNYA pendengarnya adalah
     kotak ketik di _thread. Tanpa pengganti, tombol-tombol itu diam tanpa
     kabar apa pun — kegagalan paling jahat, karena operator mengira pesannya
     terkirim.

     Jadi pendengar ini memungut kalimat yang sama persis lalu menaruhnya di
     papan klip. Konsekuensinya kalimat produk, ongkir, dan pesanan tidak perlu
     ditulis ulang di sini: yang dipakai tetap kalimat() milik masing-masing
     panel, jadi begitu Coex hidup dan mode ini dimatikan, tidak ada satu pun
     teks yang bercabang dua. --}}
<div x-data="modeWaCrm()"
     @sisip-teks.window="salin($event.detail.teks)"
     @kirim-foto-produk.window="salinFoto($event.detail)">

    {{-- Pita penjelasan mode DIBUANG (15 Sep 2026): di layar setengah monitor
         ia memakan tinggi yang dibutuhkan panel. Penanda mode kini cukup tombol
         sakelar "Kembali ke tampilan chat penuh", dan toast "Disalin — tempel
         ke WhatsApp Web" di bawah yang memberi tahu tombol Kirim berbuat apa. --}}

    {{-- Umpan balik wajib, bukan hiasan: menyalin itu tak terlihat sama sekali.
         Tanpa toast, operator menekan Kirim, tidak terjadi apa-apa di layar,
         lalu menekannya lagi berkali-kali. --}}
    <div x-show="tampil" x-cloak x-transition.opacity
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 rounded-lg px-4 py-2 text-sm font-medium shadow-lg"
         :class="galat ? 'bg-red-600 text-white' : 'bg-gray-900 text-white'"
         x-text="pesan"></div>
</div>

<script>
    function modeWaCrm() {
        return {
            pesan: '', galat: false, tampil: false, _jeda: null,

            lapor(pesan, galat = false) {
                this.pesan = pesan;
                this.galat = galat;
                this.tampil = true;
                clearTimeout(this._jeda);
                this._jeda = setTimeout(() => this.tampil = false, 2500);
            },

            async salin(teks) {
                if (! teks) return;

                if (await this.keClipboard(teks)) {
                    this.lapor('Disalin — tempel ke WhatsApp Web');
                } else {
                    this.lapor('Gagal menyalin, blokir papan klip', true);
                }
            },

            /*
             * Foto tidak bisa ikut ke papan klip: yang dipegang panel produk
             * cuma ALAMAT gambar etalase, dan menyalin berkasnya berarti
             * mengunduh lalu menulis blob — jalur yang putus di http biasa.
             * Yang disalin caption + tautannya; fotonya diseret sendiri oleh
             * operator dari tab yang dibuka tautan itu.
             */
            async salinFoto(detail) {
                const teks = [detail?.caption, detail?.foto].filter(Boolean).join('\n');
                if (! teks) return;

                if (await this.keClipboard(teks)) {
                    this.lapor('Teks & alamat foto disalin — fotonya buka dari tautan');
                } else {
                    this.lapor('Gagal menyalin, blokir papan klip', true);
                }
            },

            /*
             * navigator.clipboard hanya hidup di konteks aman. ERP yang dibuka
             * CS lewat http://<ip-lan> BUKAN konteks aman, jadi jalur modern
             * saja akan gagal diam-diam persis di komputer yang paling butuh —
             * karena itu ada jalur lama textarea+execCommand di bawahnya.
             */
            async keClipboard(teks) {
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(teks);
                        return true;
                    }
                } catch (e) { /* jatuh ke jalur lama */ }

                try {
                    const ta = document.createElement('textarea');
                    ta.value = teks;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    const ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    return ok;
                } catch (e) {
                    return false;
                }
            },
        };
    }
</script>
