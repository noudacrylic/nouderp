@extends('layouts.erp')

@php
    // Nilai tampil: prioritas input lama (setelah validasi gagal) → nilai tersimpan.
    $badges     = old('hero_badges', $homepage->hero_badges ?: []);
    $advantages = old('advantages', $homepage->advantages ?: []);
    $trust      = old('trust_items', $homepage->trust_items ?: []);
    $spotlights = old('spotlights', $homepage->spotlights ?: []);
    $segments   = old('segments',   $homepage->segments ?: []);
    $bullets    = old('institution_bullets', $homepage->institution_bullets ?: []);
    $faqs       = old('faqs',       $homepage->faqs ?: []);

    $inp   = 'border rounded px-3 py-2 w-full text-sm';
    $lbl   = 'block text-xs text-gray-500 mb-1';
    $card  = 'bg-white rounded shadow p-4 space-y-3';
    $hint  = 'text-xs text-gray-400 mt-1';
@endphp

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Beranda Website</h1>
        <p class="text-xs text-gray-500">
            Isi halaman depan noudakrilik.com. Perubahan tampil di website beberapa menit setelah disimpan.
        </p>
    </div>
    <a href="https://noudakrilik.com" target="_blank" rel="noopener"
       class="text-sm text-gray-500 hover:text-gray-700">Lihat website ↗</a>
</div>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 rounded p-3 mb-4 text-sm">
        <div class="font-semibold mb-1">Ada isian yang belum benar:</div>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('store.homepage.update') }}" enctype="multipart/form-data"
      x-data="{ tab: 'hero' }">
    @csrf @method('PUT')

    {{-- Tab bagian halaman. Urutannya sama dengan urutan bagian di beranda,
         supaya isian di sini terbaca seperti menggulung halaman aslinya. --}}
    <div class="flex flex-wrap gap-1 mb-4 border-b">
        @foreach([
            'hero'        => '1. Hero',
            'jalur'       => '2. Jalur Pembeli',
            'produk'      => '3. Produk & Kategori',
            'hemat'       => '4. Lebih Hemat',
            'sorotan'     => '5. Sorotan Produk',
            'keunggulan'  => '6. Kenapa Pilih Noud',
            'galeri'      => '7. Galeri Instansi',
            'instansi'    => '8. Instansi & Custom',
            'workshop'    => '9. Workshop',
            'faq'         => '10. FAQ',
            'kepercayaan' => '11. Strip Kepercayaan',
            'seo'         => '12. SEO & Gambar Bagikan',
        ] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-emerald-600 text-emerald-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-3 py-2 text-sm border-b-2 -mb-px whitespace-nowrap">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ══════════════════ 1. HERO ══════════════════ --}}
    <div x-show="tab === 'hero'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <div>
                <label class="{{ $lbl }}">Teks kecil di atas judul</label>
                <input type="text" name="hero_eyebrow" value="{{ old('hero_eyebrow', $homepage->hero_eyebrow) }}" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Judul utama (H1) <span class="text-red-500">*</span></label>
                <input type="text" name="hero_heading" value="{{ old('hero_heading', $homepage->hero_heading) }}" class="{{ $inp }} text-base font-semibold">
                <p class="{{ $hint }}">
                    Judul terpenting di seluruh situs — inilah yang dibaca Google sebagai isi halaman depan.
                    Sebaiknya memuat "Produsen Akrilik Semarang". Kalau dikosongkan, teks bawaan yang dipakai.
                </p>
            </div>
            <div>
                <label class="{{ $lbl }}">Kalimat penjelas</label>
                <textarea name="hero_subheading" rows="3" class="{{ $inp }}">{{ old('hero_subheading', $homepage->hero_subheading) }}</textarea>
            </div>
        </div>

        <div class="{{ $card }}">
            <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Dua Tombol</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lbl }}">Tombol utama — tulisan</label>
                    <input type="text" name="hero_primary_label" value="{{ old('hero_primary_label', $homepage->hero_primary_label) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Tombol utama — alamat tujuan</label>
                    <input type="text" name="hero_primary_url" value="{{ old('hero_primary_url', $homepage->hero_primary_url) }}" class="{{ $inp }}" placeholder="/produk">
                </div>
            </div>
            <div>
                <label class="{{ $lbl }}">Tombol kedua — tulisan</label>
                <input type="text" name="hero_secondary_label" value="{{ old('hero_secondary_label', $homepage->hero_secondary_label) }}" class="{{ $inp }}">
                <p class="{{ $hint }}">Kosongkan tulisannya bila tombol kedua tidak ingin ditampilkan.</p>
            </div>
            <div>
                <label class="{{ $lbl }}">Tombol kedua — pesan WhatsApp yang sudah terisi</label>
                <textarea name="hero_secondary_wa" rows="2" class="{{ $inp }}">{{ old('hero_secondary_wa', $homepage->hero_secondary_wa) }}</textarea>
                <p class="{{ $hint }}">Tombol ini selalu membuka WhatsApp ke nomor toko dengan pesan ini sudah tertulis.</p>
            </div>
        </div>

        @include('erp.store.homepage._image', [
            'title'   => 'Foto Hero',
            'field'   => 'hero_image',
            'url'     => $homepage->hero_image_url,
            'note'    => 'Foto besar di sebelah judul. Yang paling bagus: hasil produksi berjajar (bukti kapasitas), atau proses produksi di workshop. Mendatar, minimal 1600 px, maksimal 5 MB. Selama belum ada, kolom fotonya tidak ditampilkan dan judulnya melebar penuh.',
        ])

        <div class="{{ $card }}">
            <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Lencana di Bawah Tombol</div>
            <p class="text-xs text-gray-500">
                Empat penenang sekilas-baca. <strong>Satu baris pendek saja</strong> — penjelasannya sudah ada
                di bagian "Kenapa Pilih Noud" lebih ke bawah, dan lencana yang panjang justru tidak terbaca.
            </p>
            @include('erp.store.homepage._ikon')
            @include('erp.store.homepage._repeater', [
                'name'   => 'hero_badges',
                'rows'   => $badges,
                'max'    => 4,
                'label'  => 'Lencana',
                'fields' => [
                    ['key' => 'icon',  'label' => 'Ikon',    'width' => 'w-32',   'placeholder' => 'pabrik'],
                    ['key' => 'label', 'label' => 'Tulisan', 'width' => 'flex-1', 'placeholder' => 'Produksi sendiri di Semarang'],
                ],
            ])
        </div>

        <div class="{{ $card }}">
            <label class="{{ $lbl }}">Keterangan foto hero (alt)</label>
            <input type="text" name="hero_image_alt" value="{{ old('hero_image_alt', $homepage->hero_image_alt) }}" class="{{ $inp }}"
                   placeholder="Kotak saran akrilik hasil produksi Noud Akrilik di workshop Semarang">
            <p class="{{ $hint }}">Dibaca Google dan pembaca layar. Jelaskan isi fotonya apa adanya.</p>

            <label class="flex items-start gap-2 text-sm pt-2 border-t">
                <input type="checkbox" name="hero_image_blend" value="1"
                       @checked(old('hero_image_blend', $homepage->hero_image_blend)) class="w-4 h-4 mt-0.5">
                <span>
                    Lebur foto ke latar (tanpa bingkai)
                    <span class="block {{ $hint }}">
                        Untuk foto produk beralas <strong>putih polos</strong>: latarnya dilebur ke gradien hijau
                        sehingga produknya seolah mengambang, seperti contoh desain.
                        <strong>Matikan</strong> bila fotonya diambil di lokasi nyata (kantor, kafe, workshop) —
                        foto berlatar ramai akan ikut kehijauan; yang itu lebih bagus pakai bingkai.
                    </span>
                </span>
            </label>
        </div>
    </div>

    {{-- ══════════════════ 6. KENAPA PILIH NOUD ══════════════════ --}}
    <div x-show="tab === 'keunggulan'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_advantages" value="1" @checked(old('show_advantages', $homepage->show_advantages)) class="w-4 h-4">
                Tampilkan bagian ini
            </label>
            <div>
                <label class="{{ $lbl }}">Judul bagian</label>
                <input type="text" name="advantages_heading" value="{{ old('advantages_heading', $homepage->advantages_heading) }}" class="{{ $inp }}">
            </div>
            <p class="text-xs text-gray-500">
                Lima kartu sebaris. <strong>Hanya klaim yang berlaku untuk seluruh katalog.</strong>
                "Bentuk &amp; ukuran bebas" dan "logo instansi" TIDAK boleh masuk sini — tent card dan bingkai
                foto tidak menerima keduanya, jadi sebagai klaim situs itu salah. Tempatnya di halaman custom.
            </p>
            <p class="text-xs text-gray-500">
                Tulis <strong>mesin → akibat → manfaat</strong>, bukan "berteknologi tinggi": bagi bagian
                pengadaan itu cuma istilah, bukan alasan membeli.
            </p>
            @include('erp.store.homepage._ikon')
        </div>
        @include('erp.store.homepage._repeater', [
            'name'   => 'advantages',
            'rows'   => $advantages,
            'max'    => 6,
            'label'  => 'Keunggulan',
            'fields' => [
                ['key' => 'icon',  'label' => 'Ikon',       'width' => 'w-28',   'placeholder' => 'laser'],
                ['key' => 'title', 'label' => 'Judul',      'width' => 'flex-1', 'placeholder' => 'Dipotong mesin laser CNC'],
                ['key' => 'text',  'label' => 'Keterangan', 'width' => 'flex-1', 'placeholder' => 'Tepi potongan bening dan rapi tanpa perlu diamplas.', 'textarea' => true],
            ],
        ])
    </div>

    {{-- ══════════════════ 2. JALUR PEMBELI ══════════════════ --}}
    <div x-show="tab === 'jalur'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_segments" value="1" @checked(old('show_segments', $homepage->show_segments)) class="w-4 h-4">
                Tampilkan bagian ini
            </label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lbl }}">Judul bagian</label>
                    <input type="text" name="segments_heading" value="{{ old('segments_heading', $homepage->segments_heading) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Kalimat di bawah judul</label>
                    <input type="text" name="segments_subheading" value="{{ old('segments_subheading', $homepage->segments_subheading) }}" class="{{ $inp }}">
                </div>
            </div>
            <p class="text-xs text-gray-500">
                Penyortir utama beranda: sebelas kategori berjajar terlalu banyak untuk dicerna dalam lima detik.
                Satu kategori boleh muncul di dua kartu — pembeli berbeda mencari barang yang sama dengan kata
                yang berbeda.
            </p>
            <p class="text-xs text-gray-500">
                <strong>Kolom WhatsApp mengubah tujuan kartu.</strong> Kartu yang diisi pesan WhatsApp akan
                membuka chat, bukan katalog, dan tampil berbeda — itu kartu untuk orang yang barangnya memang
                tidak ada di katalog. Bila keduanya diisi, WhatsApp yang menang.
            </p>
            @include('erp.store.homepage._ikon')
        </div>
        @include('erp.store.homepage._repeater', [
            'name'   => 'segments',
            'rows'   => $segments,
            'max'    => 6,
            'label'  => 'Kartu',
            'fields' => [
                ['key' => 'icon',  'label' => 'Ikon',   'width' => 'w-28',   'placeholder' => 'koper'],
                ['key' => 'title', 'label' => 'Judul',  'width' => 'flex-1', 'placeholder' => 'Kantor & Instansi'],
                ['key' => 'text',  'label' => 'Isi',    'width' => 'flex-1', 'placeholder' => 'Kotak saran, papan nama meja, tempat brosur…', 'textarea' => true],
                ['key' => 'url',   'label' => 'Tujuan', 'width' => 'flex-1', 'placeholder' => '/kategori/tempat-brosur'],
                ['key' => 'wa',    'label' => 'Pesan WhatsApp (opsional)', 'width' => 'flex-1', 'placeholder' => 'Halo Noud Akrilik, saya ingin akrilik custom…', 'textarea' => true],
            ],
        ])
    </div>

    {{-- ══════════════════ 3. KATEGORI & PRODUK ══════════════════ --}}
    <div x-show="tab === 'produk'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Grid Kategori</div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_categories" value="1" @checked(old('show_categories', $homepage->show_categories)) class="w-4 h-4">
                Tampilkan daftar kategori di beranda
            </label>
            <div>
                <label class="{{ $lbl }}">Judul bagian</label>
                <input type="text" name="categories_heading" value="{{ old('categories_heading', $homepage->categories_heading) }}" class="{{ $inp }}">
            </div>
            <p class="{{ $hint }}">
                Isi & urutannya mengikuti <a href="{{ route('store.categories.index') }}" class="text-emerald-700 underline">Store → Kategori</a>
                (kolom urutan), bukan diatur di sini.
            </p>
        </div>

        <div class="{{ $card }}">
            <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Produk Unggulan</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="md:col-span-2">
                    <label class="{{ $lbl }}">Judul bagian</label>
                    <input type="text" name="featured_heading" value="{{ old('featured_heading', $homepage->featured_heading) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Jumlah produk tampil</label>
                    <input type="number" name="featured_limit" min="2" max="24"
                           value="{{ old('featured_limit', $homepage->featured_limit) }}" class="{{ $inp }}">
                </div>
            </div>
            <p class="{{ $hint }}">
                Produk mana yang tampil ditentukan oleh centang <strong>"Jadikan unggulan"</strong> di
                <a href="{{ route('store.products.index') }}" class="text-emerald-700 underline">Store → Produk Store</a>,
                urutannya mengikuti kolom urutan produk. Bila belum ada satu pun yang dicentang, beranda menampilkan produk terbaru.
            </p>
        </div>
    </div>

    {{-- ══════════════════ 4. LEBIH HEMAT ══════════════════ --}}
    <div x-show="tab === 'hemat'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_savings" value="1" @checked(old('show_savings', $homepage->show_savings)) class="w-4 h-4">
                Tampilkan bagian ini
            </label>
            <div>
                <label class="{{ $lbl }}">Judul bagian</label>
                <input type="text" name="savings_heading" value="{{ old('savings_heading', $homepage->savings_heading) }}" class="{{ $inp }}">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="{{ $card }}">
                <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Kolom Kiri — Harga</div>
                <input type="text" name="savings_price_title" value="{{ old('savings_price_title', $homepage->savings_price_title) }}" class="{{ $inp }} font-semibold">
                <textarea name="savings_price_text" rows="4" class="{{ $inp }}">{{ old('savings_price_text', $homepage->savings_price_text) }}</textarea>
            </div>
            <div class="{{ $card }}">
                <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Kolom Kanan — Ongkir</div>
                <input type="text" name="savings_ship_title" value="{{ old('savings_ship_title', $homepage->savings_ship_title) }}" class="{{ $inp }} font-semibold">
                <textarea name="savings_ship_text" rows="4" class="{{ $inp }}">{{ old('savings_ship_text', $homepage->savings_ship_text) }}</textarea>
                <div>
                    <label class="{{ $lbl }}">Tulisan tautan ke halaman ketentuan</label>
                    <input type="text" name="savings_link_label" value="{{ old('savings_link_label', $homepage->savings_link_label) }}" class="{{ $inp }}">
                </div>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800 space-y-1">
            <p>
                <strong>Angka tangga potongan ongkirnya tidak diketik di sini.</strong> Halaman
                <code>noudakrilik.com/potongan-ongkir</code> membaca langsung promo ongkir yang sedang aktif di
                <a href="/erp/sales/promosi" class="underline">Sales → Promosi</a>.
                Ubah tingkatannya di sana, halaman web ikut berubah sendiri — dan mustahil berbeda dengan hitungan checkout.
            </p>
            <p>
                Tulis <strong>"potongan ongkir"</strong>, jangan "gratis ongkir": ongkirnya memang tidak gratis,
                hanya dipotong, dan kata "gratis" memicu komplain.
            </p>
        </div>
    </div>

    {{-- ══════════════════ 5. SOROTAN PRODUK ══════════════════ --}}
    <div x-show="tab === 'sorotan'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_spotlight" value="1" @checked(old('show_spotlight', $homepage->show_spotlight)) class="w-4 h-4">
                Tampilkan bagian ini
            </label>
            <p class="text-xs text-gray-500">
                Produk bernilai pesanan tinggi diberi ruang penuh dan bisa digeser satu per satu.
                Kartu di grid terlalu sempit untuk menjelaskan kenapa barang seharga jutaan itu masuk akal —
                bagian ini yang mengerjakannya.
            </p>
            <div class="bg-emerald-50 border border-emerald-200 rounded p-3 text-xs text-emerald-900 space-y-1">
                <p>
                    <strong>Foto, nama, dan harga tidak diketik di sini.</strong> Cukup isi alamat produknya (slug);
                    website mengambil sendiri foto utama, nama, dan harga dari
                    <a href="{{ route('store.products.index') }}" class="underline">Store → Produk Store</a>.
                    Jadi begitu foto produknya diperbarui, slide ini ikut berubah — tidak pernah basi.
                </p>
                <p>
                    Cara mengisi slug: buka halaman produknya di website, salin bagian akhir alamatnya.
                    Dari <code>noudakrilik.com/produk/<strong>kotak-saran-akrilik</strong></code> yang diisi
                    <code>kotak-saran-akrilik</code>. Slug yang tidak ditemukan akan dilewati diam-diam.
                </p>
            </div>
            <p class="text-xs text-gray-500">
                Isinya layak digilir tiap beberapa minggu, dan pilih yang alasannya berbeda-beda:
                nilai pesanan tertinggi, produk terlaris, dan kata kunci paling banyak dicari.
            </p>
        </div>

        @include('erp.store.homepage._repeater', [
            'name'   => 'spotlights',
            'rows'   => $spotlights,
            'max'    => 6,
            'label'  => 'Slide',
            'fields' => [
                ['key' => 'slug',      'label' => 'Alamat produk (slug)',   'width' => 'flex-1', 'placeholder' => 'box-charger-hp-12-kotak'],
                ['key' => 'eyebrow',   'label' => 'Teks kecil di atas judul', 'width' => 'flex-1', 'placeholder' => 'Solusi charging untuk kantor & instansi'],
                ['key' => 'heading',   'label' => 'Judul (kosong = nama produk)', 'width' => 'flex-1', 'placeholder' => 'Box Charger HP 12 Kotak'],
                ['key' => 'body',      'label' => 'Kalimat penjelas',       'width' => 'flex-1', 'placeholder' => 'Charging station dengan 12 loker terpisah…', 'textarea' => true],
                ['key' => 'bullets',   'label' => 'Manfaat — satu per baris', 'width' => 'flex-1', 'placeholder' => "Kunci berbeda tiap pintu\nStop kontak sudah terpasang", 'textarea' => true],
                ['key' => 'cta_label', 'label' => 'Tulisan tombol',         'width' => 'flex-1', 'placeholder' => 'Lihat Detail Box Charger'],
            ],
        ])
    </div>

    {{-- ══════════════════ 7. GALERI INSTANSI ══════════════════ --}}
    <div x-show="tab === 'galeri'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_gallery" value="1" @checked(old('show_gallery', $homepage->show_gallery)) class="w-4 h-4">
                Tampilkan bagian ini
            </label>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="md:col-span-2">
                    <label class="{{ $lbl }}">Judul bagian</label>
                    <input type="text" name="gallery_heading" value="{{ old('gallery_heading', $homepage->gallery_heading) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Jumlah foto tampil</label>
                    <input type="number" name="gallery_limit" min="3" max="16"
                           value="{{ old('gallery_limit', $homepage->gallery_limit) }}" class="{{ $inp }}">
                </div>
            </div>
            <div>
                <label class="{{ $lbl }}">Kalimat di bawah judul</label>
                <input type="text" name="gallery_note" value="{{ old('gallery_note', $homepage->gallery_note) }}" class="{{ $inp }}">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lbl }}">Tulisan tautan (opsional)</label>
                    <input type="text" name="gallery_link_label" value="{{ old('gallery_link_label', $homepage->gallery_link_label) }}" class="{{ $inp }}"
                           placeholder="Lihat semua galeri">
                </div>
                <div>
                    <label class="{{ $lbl }}">Alamat tautan (opsional)</label>
                    <input type="text" name="gallery_url" value="{{ old('gallery_url', $homepage->gallery_url) }}" class="{{ $inp }}">
                    <p class="{{ $hint }}">Tautan hanya muncul bila keduanya diisi. Halaman galeri tersendiri belum ada.</p>
                </div>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800 space-y-1">
            <p>
                <strong>Fotonya tidak diunggah di halaman ini.</strong> Yang tampil adalah foto
                <strong>Showcase</strong> milik tiap produk di
                <a href="{{ route('store.products.index') }}" class="underline">Store → Produk Store</a> —
                foto yang sama yang muncul di bawah deskripsi produknya. Sekali unggah, dipakai di dua tempat.
            </p>
            <p>
                Diambil paling banyak dua foto per produk supaya galerinya tidak habis oleh satu produk saja.
                Isi kolom <em>keterangan</em> foto dengan nama instansinya — itu yang tampil di bawah gambar.
                Samarkan logo bila belum ada izin penyebutan nama.
            </p>
            <p>
                Pastikan fotonya benar-benar hasil kerja sendiri. Foto contoh milik orang lain adalah risiko
                yang tidak sebanding dengan manfaatnya.
            </p>
        </div>
    </div>

    {{-- ══════════════════ 8. INSTANSI & CUSTOM ══════════════════ --}}
    <div x-show="tab === 'instansi'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_institution" value="1" @checked(old('show_institution', $homepage->show_institution)) class="w-4 h-4">
                Tampilkan bagian pengadaan instansi
            </label>
            <div>
                <label class="{{ $lbl }}">Judul</label>
                <input type="text" name="institution_heading" value="{{ old('institution_heading', $homepage->institution_heading) }}" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Isi</label>
                <textarea name="institution_body" rows="3" class="{{ $inp }}">{{ old('institution_body', $homepage->institution_body) }}</textarea>
            </div>

            <div x-data="{ rows: {{ json_encode(array_values($bullets) ?: ['']) }} }">
                <label class="{{ $lbl }}">Daftar centang</label>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="flex gap-2 mb-2">
                        <input type="text" :name="`institution_bullets[${i}]`" x-model="rows[i]" class="{{ $inp }}">
                        <button type="button" @click="rows.splice(i, 1)"
                                class="text-red-500 hover:text-red-700 px-2 text-sm shrink-0">Hapus</button>
                    </div>
                </template>
                <button type="button" @click="rows.length < 8 && rows.push('')"
                        class="text-xs border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-3 py-1.5">
                    + Tambah baris
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                <div>
                    <label class="{{ $lbl }}">Tombol — tulisan</label>
                    <input type="text" name="institution_cta_label" value="{{ old('institution_cta_label', $homepage->institution_cta_label) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Tombol — pesan WhatsApp</label>
                    <textarea name="institution_cta_wa" rows="2" class="{{ $inp }}">{{ old('institution_cta_wa', $homepage->institution_cta_wa) }}</textarea>
                </div>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800">
                <strong>Jangan menulis harga grosir, harga khusus, atau jenjang diskon jumlah</strong> di bagian ini
                maupun di mana pun di situs. Jenjangnya belum ditentukan dan masih menunggu modul Analisa —
                begitu kata "grosir" muncul di web, pembeli akan menuntut daftar harganya. Penawaran dihitung
                manual lewat WhatsApp.
            </div>
        </div>

        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_custom" value="1" @checked(old('show_custom', $homepage->show_custom)) class="w-4 h-4">
                Tampilkan bagian custom nama & logo
            </label>
            <div>
                <label class="{{ $lbl }}">Judul</label>
                <input type="text" name="custom_heading" value="{{ old('custom_heading', $homepage->custom_heading) }}" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Isi</label>
                <textarea name="custom_body" rows="4" class="{{ $inp }}">{{ old('custom_body', $homepage->custom_body) }}</textarea>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lbl }}">Tombol — tulisan</label>
                    <input type="text" name="custom_cta_label" value="{{ old('custom_cta_label', $homepage->custom_cta_label) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Tombol — pesan WhatsApp</label>
                    <textarea name="custom_cta_wa" rows="2" class="{{ $inp }}">{{ old('custom_cta_wa', $homepage->custom_cta_wa) }}</textarea>
                </div>
            </div>
            <p class="{{ $hint }}">
                Belum ada foto portofolio, jadi bagian ini masih versi teks. Setelah terkumpul 6–8 foto hasil
                custom berlogo instansi, bagian ini layak diperbesar jadi galeri.
                Jangan memasang foto contoh yang bukan hasil kerja sendiri.
            </p>
        </div>
    </div>

    {{-- ══════════════════ 9. WORKSHOP ══════════════════ --}}
    <div x-show="tab === 'workshop'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_workshop" value="1" @checked(old('show_workshop', $homepage->show_workshop)) class="w-4 h-4">
                Tampilkan bagian workshop
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_map" value="1" @checked(old('show_map', $homepage->show_map)) class="w-4 h-4">
                Tampilkan peta tersemat
            </label>
            <div>
                <label class="{{ $lbl }}">Judul</label>
                <input type="text" name="workshop_heading" value="{{ old('workshop_heading', $homepage->workshop_heading) }}" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Isi</label>
                <textarea name="workshop_body" rows="3" class="{{ $inp }}">{{ old('workshop_body', $homepage->workshop_body) }}</textarea>
            </div>
            <p class="{{ $hint }}">
                Alamat, jam buka, dan nomor telepon <strong>tidak diketik di sini</strong> — ketiganya sudah tertanam
                di website dan harus sama persis dengan Profil Bisnis Google. Dua sumber yang bisa diketik terpisah
                cepat atau lambat akan berbeda, dan bedanya melemahkan pencarian lokal.
                Hanya satu alamat yang ditampilkan (Jl. Suren Raya), bukan keduanya.
            </p>
        </div>
    </div>

    {{-- ══════════════════ 10. FAQ ══════════════════ --}}
    <div x-show="tab === 'faq'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_faq" value="1" @checked(old('show_faq', $homepage->show_faq)) class="w-4 h-4">
                Tampilkan bagian FAQ
            </label>
            <div>
                <label class="{{ $lbl }}">Judul bagian</label>
                <input type="text" name="faq_heading" value="{{ old('faq_heading', $homepage->faq_heading) }}" class="{{ $inp }}">
            </div>
            <p class="{{ $hint }}">
                Pertanyaan di sini juga dikirim ke Google sebagai data terstruktur FAQ — jawabannya bisa muncul
                langsung di hasil pencarian. Karena itu jawablah selengkap dan sejujur mungkin; jangan menjanjikan
                yang belum tentu bisa dipenuhi.
            </p>
        </div>
        @include('erp.store.homepage._repeater', [
            'name'   => 'faqs',
            'rows'   => $faqs,
            'max'    => 15,
            'label'  => 'Pertanyaan',
            'fields' => [
                ['key' => 'q', 'label' => 'Pertanyaan', 'width' => 'flex-1', 'placeholder' => 'Apakah bisa beli satuan?'],
                ['key' => 'a', 'label' => 'Jawaban',    'width' => 'flex-1', 'placeholder' => 'Bisa. Kami melayani pembelian satuan sampai borongan.', 'textarea' => true],
            ],
        ])
    </div>

    {{-- ══════════════════ 11. STRIP KEPERCAYAAN ══════════════════ --}}
    <div x-show="tab === 'kepercayaan'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_trust" value="1" @checked(old('show_trust', $homepage->show_trust)) class="w-4 h-4">
                Tampilkan bagian ini
            </label>
            <p class="text-xs text-gray-500">
                Strip tipis tepat sebelum footer. Isinya <strong>kepastian layanan</strong> — cara bayar, jam CS,
                jangkauan kirim. Jangan mengulang klaim produk dari bagian "Kenapa Pilih Noud": kalau isinya sama,
                pembaca berhenti membaca keduanya.
            </p>
            <p class="text-xs text-gray-500">
                Jangan menulis angka yang tidak bisa dibuktikan (mis. "potongan ongkir hingga 30%") —
                potongannya berupa rupiah bertingkat, bukan persentase.
            </p>
            @include('erp.store.homepage._ikon')
        </div>
        @include('erp.store.homepage._repeater', [
            'name'   => 'trust_items',
            'rows'   => $trust,
            'max'    => 6,
            'label'  => 'Item',
            'fields' => [
                ['key' => 'icon',  'label' => 'Ikon',        'width' => 'w-28',   'placeholder' => 'kartu'],
                ['key' => 'title', 'label' => 'Baris atas',  'width' => 'flex-1', 'placeholder' => 'Pembayaran aman'],
                ['key' => 'text',  'label' => 'Baris bawah', 'width' => 'flex-1', 'placeholder' => 'QRIS & transfer bank'],
            ],
        ])
    </div>

    {{-- ══════════════════ 12. SEO & GAMBAR BAGIKAN ══════════════════ --}}
    <div x-show="tab === 'seo'" x-cloak class="space-y-4">
        <div class="{{ $card }}">
            <div>
                <label class="{{ $lbl }}">Judul di hasil pencarian Google (meta title)</label>
                <input type="text" name="meta_title" value="{{ old('meta_title', $homepage->meta_title) }}" class="{{ $inp }}" maxlength="255">
                <p class="{{ $hint }}">Idealnya di bawah 60 karakter agar tidak terpotong. Harus memuat kata yang benar-benar diketik orang.</p>
            </div>
            <div>
                <label class="{{ $lbl }}">Keterangan di hasil pencarian (meta description)</label>
                <textarea name="meta_description" rows="3" maxlength="300" class="{{ $inp }}">{{ old('meta_description', $homepage->meta_description) }}</textarea>
                <p class="{{ $hint }}">Idealnya 150–160 karakter.</p>
            </div>
        </div>

        @include('erp.store.homepage._image', [
            'title'   => 'Gambar Bagikan (WhatsApp / Facebook)',
            'field'   => 'og_image',
            'url'     => $homepage->og_image_url,
            'note'    => 'Gambar yang muncul saat tautan beranda dibagikan di WhatsApp. Ukuran 1200×630 px. Jangan logo di atas latar putih — di kotak kecil WhatsApp itu tak menarik untuk diklik. Pakai foto produk atau workshop dengan satu obyek jelas. Bila dikosongkan, website memakai logo.',
        ])
    </div>

    {{-- Aksi --}}
    <div class="sticky bottom-0 mt-4 bg-white border-t shadow-inner px-4 py-3 flex items-center gap-2 rounded-b">
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded font-semibold text-sm">
            ✓ Simpan Beranda
        </button>
        <span class="text-xs text-gray-400">
            Semua tab tersimpan sekaligus — tidak perlu menyimpan satu per satu.
        </span>
    </div>
</form>

{{-- Kembalikan ke teks bawaan: form terpisah supaya tidak ikut tersubmit tak sengaja. --}}
<form method="POST" action="{{ route('store.homepage.reset') }}" class="mt-3"
      onsubmit="return confirm('Kembalikan SEMUA teks beranda ke bawaan? Suntingan Anda akan hilang. Gambar yang sudah diunggah tetap aman.')">
    @csrf
    <button type="submit" class="text-xs border border-gray-300 text-gray-500 hover:bg-gray-50 rounded px-3 py-1.5">
        Kembalikan ke teks bawaan
    </button>
</form>
@endsection
