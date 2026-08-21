{{--
    Mesin hitung simulasi promo (sisi peramban).

    Urutannya sengaja mengikuti urutan uang benar-benar berkurang:

        subtotal − diskon item − diskon belanja = pendapatan barang
        pendapatan barang − potongan admin − diskon ongkir − HPP = untung

    Diskon ongkir dikurangkan UTUH dari untung, bukan dari pendapatan: yang digratiskan itu
    ongkos kirim yang tetap harus dibayar ke kurir, jadi ia beban — bukan penjualan yang
    berkurang. Potongan admin dihitung dari pendapatan barang saja.
--}}
<script>
(function () {
    const tbody = document.getElementById('baris-keranjang');
    if (!tbody) return;

    const tpl = document.getElementById('tpl-baris');

    // Katalog dipegang di sini (bukan sebagai <option>) supaya pencariannya bisa mencocokkan
    // SKU maupun nama di posisi mana pun, bukan cuma huruf pertama seperti dropdown biasa.
    const KATALOG = @json($katalog).map((p) => ({
        ...p,
        cari: (p.sku + ' ' + p.nama).toLowerCase(),
        label: p.sku + ' · ' + p.nama,
    }));

    // Nama produk diketik manusia; jangan pernah ditempel mentah ke innerHTML.
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const rupiah = (v) => 'Rp' + Math.round(v || 0).toLocaleString('id-ID');
    const persen = (v) => v === null ? '—' : v.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    const angka = (el) => el ? parseFloat(String(el.value).replace(/[^\d]/g, '')) || 0 : 0;
    const desimal = (el) => el ? parseFloat(String(el.value).replace(',', '.')) || 0 : 0;

    function tambahBaris() {
        tbody.appendChild(tpl.content.cloneNode(true));
        hitung();
    }

    /** Satu baris keranjang → nilai bersih & HPP-nya. */
    function baris(tr) {
        const hpp   = tr.dataset.hpp === '' ? null : parseFloat(tr.dataset.hpp);
        const qty   = Math.max(1, parseInt(tr.querySelector('.js-qty').value || '1', 10) || 1);
        const harga = angka(tr.querySelector('.js-harga'));

        const bruto  = harga * qty;
        const diskon = bruto * (desimal(tr.querySelector('.js-dpersen')) / 100) + angka(tr.querySelector('.js-drupiah'));
        const bersih = Math.max(0, bruto - diskon);

        tr.querySelector('.js-bersih').textContent = rupiah(bersih);
        tr.querySelector('.js-hpp').textContent    = hpp === null ? '—' : rupiah(hpp * qty);

        return { bruto, diskon, bersih, hpp: hpp === null ? null : hpp * qty, hppKosong: hpp === null && qty > 0 && harga > 0 };
    }

    function hitung() {
        let subtotal = 0, diskonItem = 0, hpp = 0, adaHppKosong = false;

        tbody.querySelectorAll('tr').forEach((tr) => {
            const b = baris(tr);
            subtotal   += b.bruto;
            diskonItem += b.diskon;
            if (b.hpp === null) { adaHppKosong = adaHppKosong || b.hppKosong; } else { hpp += b.hpp; }
        });

        const diskonBelanja = angka(document.getElementById('i-diskon-belanja'));
        const diskonOngkir  = angka(document.getElementById('i-diskon-ongkir'));
        const adminPersen   = desimal(document.getElementById('i-admin-persen'));
        const adminRp       = angka(document.getElementById('i-admin-rp'));

        const pendapatan = Math.max(0, subtotal - diskonItem - diskonBelanja);
        const admin      = pendapatan > 0 ? pendapatan * (adminPersen / 100) + adminRp : 0;
        const untung     = pendapatan - admin - diskonOngkir - hpp;

        document.getElementById('v-subtotal').textContent    = rupiah(subtotal);
        document.getElementById('v-diskon-item').textContent = '−' + rupiah(diskonItem);
        document.getElementById('v-admin').textContent       = '−' + rupiah(admin);
        document.getElementById('v-pendapatan').textContent  = rupiah(pendapatan);
        document.getElementById('v-hpp').textContent         = rupiah(hpp) + (adaHppKosong ? ' + ?' : '');

        const elUntung = document.getElementById('v-untung');
        elUntung.textContent = rupiah(untung);
        elUntung.classList.toggle('text-red-600', untung < 0);
        elUntung.classList.toggle('text-slate-900', untung >= 0);

        document.getElementById('v-margin').textContent = pendapatan > 0 ? persen(untung / pendapatan * 100) : '—';
        document.getElementById('v-markup').textContent = hpp > 0 ? persen(untung / hpp * 100) : '—';
        document.getElementById('v-peringatan').classList.toggle('hidden', untung >= 0 || pendapatan === 0);
    }

    // ── Pencarian produk ──────────────────────────────────────────────
    // Diketik, bukan digulir: daftarnya ratusan produk, dan yang dicari biasanya sudah
    // diingat sebagian namanya atau SKU-nya.
    //
    // Daftar hasilnya SATU elemen milik halaman (position: fixed), bukan satu per baris di
    // dalam sel tabel. Sel tabel berada di dalam kotak ber-overflow — apa pun yang menggantung
    // ke bawah dari sana akan terpotong dan hasil pencariannya seolah tidak muncul.

    const kotak = document.createElement('div');
    kotak.className = 'hidden fixed z-50 bg-white border border-slate-200 rounded-xl shadow-lg max-h-72 overflow-auto py-1';
    document.body.appendChild(kotak);

    let barisAktif = null;

    function tutupHasil() {
        kotak.classList.add('hidden');
        barisAktif = null;
    }

    function tampilkanHasil(tr, query) {
        barisAktif = tr;

        const input = tr.querySelector('.js-produk');
        const q     = query.toLowerCase().trim();
        const hasil = (q ? KATALOG.filter((p) => p.cari.includes(q)) : KATALOG).slice(0, 30);

        kotak.innerHTML = hasil.length
            ? hasil.map((p) => `
                <button type="button" class="js-pilih w-full text-left px-3 py-1.5 text-sm hover:bg-blue-50"
                        data-id="${p.id}" data-hpp="${p.hpp === null ? '' : p.hpp}" data-harga="${Math.round(p.harga || 0)}">
                    <span class="font-mono font-semibold text-blue-600">${esc(p.sku)}</span>
                    <span class="text-slate-700">${esc(p.nama)}</span>
                    <span class="block text-[10px] text-slate-400">
                        HPP ${p.hpp === null ? 'belum ada' : rupiah(p.hpp)} · harga ${rupiah(p.harga)}
                    </span>
                </button>`).join('')
            : '<div class="px-3 py-2 text-sm text-slate-400">Tidak ada produk yang cocok.</div>';

        const r = input.getBoundingClientRect();
        kotak.style.left  = r.left + 'px';
        kotak.style.width = Math.max(r.width, 320) + 'px';
        // Buka ke atas kalau ruang di bawahnya tidak cukup — baris terakhir keranjang biasanya
        // dekat tepi layar.
        const ruangBawah = window.innerHeight - r.bottom;
        if (ruangBawah < 200) {
            kotak.style.top = '';
            kotak.style.bottom = (window.innerHeight - r.top + 4) + 'px';
        } else {
            kotak.style.bottom = '';
            kotak.style.top = (r.bottom + 4) + 'px';
        }

        kotak.classList.remove('hidden');
    }

    function pilihProduk(tr, tombol) {
        tr.dataset.pid = tombol.dataset.id;
        tr.dataset.hpp = tombol.dataset.hpp;
        // Label diambil dari katalog, bukan dari teks tombol yang juga memuat keterangan HPP.
        const p = KATALOG.find((x) => String(x.id) === String(tombol.dataset.id));
        tr.querySelector('.js-produk').value = p ? p.label : '';

        tr.querySelector('.js-harga').value = parseInt(tombol.dataset.harga || '0', 10).toLocaleString('id-ID');
        tutupHasil();
        hitung();
    }

    tbody.addEventListener('input', (e) => {
        const tr = e.target.closest('tr');
        if (e.target.matches('.js-produk')) {
            // Mengetik ulang membatalkan pilihan sebelumnya — supaya tidak ada baris yang
            // namanya satu produk tapi HPP-nya milik produk lain.
            tr.dataset.pid = '';
            tr.dataset.hpp = '';
            tampilkanHasil(tr, e.target.value);
        }
        hitung();
    });

    tbody.addEventListener('focusin', (e) => {
        if (e.target.matches('.js-produk')) tampilkanHasil(e.target.closest('tr'), e.target.value);
    });

    tbody.addEventListener('click', (e) => {
        if (e.target.matches('.js-hapus')) {
            if (barisAktif === e.target.closest('tr')) tutupHasil();
            e.target.closest('tr').remove();
            hitung();
        }
    });

    // Memilih dari daftar (daftarnya di luar tabel, jadi klik-nya ditangkap di sini).
    kotak.addEventListener('click', (e) => {
        const pilih = e.target.closest('.js-pilih');
        if (pilih && barisAktif) pilihProduk(barisAktif, pilih);
    });

    // Enter mengambil hasil teratas — mengetik lalu Enter adalah jalan tercepatnya.
    tbody.addEventListener('keydown', (e) => {
        if (!e.target.matches('.js-produk')) return;
        if (e.key === 'Escape') { tutupHasil(); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const pertama = kotak.querySelector('.js-pilih');
        if (pertama && barisAktif) pilihProduk(barisAktif, pertama);
    });

    // Klik di luar, gulir, atau ubah ukuran layar menutup daftar yang sedang terbuka —
    // posisinya dikunci ke layar, jadi ia tidak ikut bergerak bersama halaman.
    document.addEventListener('click', (e) => {
        if (e.target.closest('.js-produk') || kotak.contains(e.target)) return;
        tutupHasil();
    });
    window.addEventListener('scroll', tutupHasil, true);
    window.addEventListener('resize', tutupHasil);

    ['i-diskon-belanja', 'i-diskon-ongkir', 'i-admin-persen', 'i-admin-rp']
        .forEach((id) => document.getElementById(id).addEventListener('input', hitung));

    document.getElementById('btn-tambah').addEventListener('click', tambahBaris);

    // Promo yang benar-benar aktif — dihitung di server oleh PromotionService yang sama
    // dengan penjualan sungguhan, lalu diisikan ke kolom diskon.
    document.getElementById('btn-promo-aktif').addEventListener('click', async function () {
        const items = [];
        tbody.querySelectorAll('tr').forEach((tr) => {
            const pid = tr.dataset.pid;
            if (!pid) return;
            items.push({
                product_id: parseInt(pid, 10),
                qty: Math.max(1, parseInt(tr.querySelector('.js-qty').value || '1', 10) || 1),
                unit_price: angka(tr.querySelector('.js-harga')),
            });
        });
        if (!items.length) return;

        this.disabled = true;
        try {
            const resp = await fetch('{{ route('analisa.harga.promo.aktif') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ items, shipping: 0 }),
            });
            const data = await resp.json();

            const perProduk = {};
            (data.items || []).forEach((d) => { perProduk[d.product_id] = d.amount; });

            tbody.querySelectorAll('tr').forEach((tr) => {
                const pid = parseInt(tr.dataset.pid || '0', 10);
                tr.querySelector('.js-dpersen').value = 0;
                tr.querySelector('.js-drupiah').value = Math.round(perProduk[pid] || 0).toLocaleString('id-ID');
            });

            document.getElementById('i-diskon-belanja').value = Math.round(data.cart || 0).toLocaleString('id-ID');
            document.getElementById('i-diskon-ongkir').value  = Math.round(data.shipping || 0).toLocaleString('id-ID');

            const nama = document.getElementById('v-promo-nama');
            nama.textContent = (data.names || []).length ? 'Promo terpakai: ' + data.names.join(', ') : 'Tidak ada promo aktif yang cocok untuk isi keranjang ini.';
            nama.classList.remove('hidden');

            hitung();
        } finally {
            this.disabled = false;
        }
    });

    tambahBaris();
})();
</script>
