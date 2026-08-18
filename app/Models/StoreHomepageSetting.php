<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Isi beranda etalase — singleton (satu baris). Dikelola di Store → Beranda,
 * dibaca etalase lewat GET /api/storefront/homepage.
 *
 * Baris pertama dibuat sudah TERISI teks bawaan (lihat defaults()), bukan kosong:
 * halaman pengaturan yang terbuka dengan 40 kotak kosong praktis mustahil diisi
 * dari nol, dan beranda ikut kosong sampai seseorang sempat mengetiknya.
 */
class StoreHomepageSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'hero_badges'         => 'array',
        'advantages'          => 'array',
        'segments'            => 'array',
        'spotlights'          => 'array',
        'institution_bullets' => 'array',
        'trust_items'         => 'array',
        'faqs'                => 'array',
        'hero_image_blend'    => 'boolean',
        'show_segments'       => 'boolean',
        'show_advantages'     => 'boolean',
        'show_categories'     => 'boolean',
        'show_savings'        => 'boolean',
        'show_spotlight'      => 'boolean',
        'show_gallery'        => 'boolean',
        'show_institution'    => 'boolean',
        'show_custom'         => 'boolean',
        'show_workshop'       => 'boolean',
        'show_map'            => 'boolean',
        'show_trust'          => 'boolean',
        'show_faq'            => 'boolean',
        'featured_limit'      => 'integer',
        'gallery_limit'       => 'integer',
    ];

    /**
     * Ambil baris tunggal, buat berisi teks bawaan bila belum ada.
     * Pola `oldest('id')->first()` mengikuti StorefrontSetting/R2Setting — bukan
     * firstOrCreate(['id' => 1]) yang rapuh saat auto-increment sudah lewat 1.
     */
    public static function singleton(): self
    {
        return self::query()->oldest('id')->first() ?? self::create(self::defaults());
    }

    /**
     * Teks bawaan beranda. Sengaja lengkap: ini juga yang tampil di form ERP saat
     * pertama dibuka, jadi mengubahnya cukup menyunting kalimat, bukan mengarang.
     *
     * Catatan isi:
     *  - Kata "produsen", bukan "kerajinan" — pembeli instansi mencari supplier,
     *    dan "kerajinan" menempatkan Noud di rak suvenir.
     *  - Tidak ada satu pun klaim harga grosir / jenjang diskon jumlah. Jenjangnya
     *    belum ditentukan; begitu muncul di web, pembeli menuntut daftar harganya.
     *  - Ditulis "potongan ongkir", TIDAK PERNAH "gratis ongkir" — ongkirnya memang
     *    tidak gratis, cuma dipotong, dan kata "gratis" memicu komplain.
     */
    public static function defaults(): array
    {
        return [
            'meta_title'       => 'Produsen Akrilik Semarang — Noud Acrylic Shop',
            'meta_description' => 'Produsen akrilik Semarang sejak 2018. Tempat brosur, kotak saran, papan nama, box mahar, dan akrilik custom. Stok real-time, harga web lebih murah.',

            'hero_eyebrow'         => 'Produksi sendiri di Semarang sejak 2018',
            'hero_heading'         => 'Produsen Akrilik Semarang untuk Kantor, Usaha & Instansi',
            'hero_subheading'      => 'Tempat brosur, kotak saran, papan nama meja, box charger, hingga akrilik custom. Dibuat sendiri di workshop Banyumanik — melayani satuan sampai borongan, kirim ke seluruh Indonesia.',
            'hero_primary_label'   => 'Lihat Katalog',
            'hero_primary_url'     => '/produk',
            'hero_secondary_label' => 'Konsultasi Pembelian Instansi',
            'hero_secondary_wa'    => 'Halo Noud Akrilik, saya ingin bertanya untuk pembelian dalam jumlah banyak.',
            'hero_image_blend'     => false,

            // Lencana tipis di bawah tombol hero. Sengaja label pendek satu baris:
            // ini penenang sekilas-baca, bukan tempat menjelaskan — penjelasannya
            // ada di bagian "Kenapa pilih Noud" di bawah.
            'hero_badges' => [
                ['icon' => 'pabrik', 'label' => 'Produksi sendiri di Semarang'],
                ['icon' => 'kotak',  'label' => 'Stok real-time siap dikirim'],
                ['icon' => 'tag',    'label' => 'Harga di web lebih murah'],
                ['icon' => 'truk',   'label' => 'Kirim ke seluruh Indonesia'],
            ],

            // Hanya keunggulan GLOBAL — yang berlaku untuk SEMUA produk. Bentuk &
            // ukuran bebas dan logo instansi TIDAK boleh naik ke sini: tent card dan
            // bingkai foto tidak menerima keduanya, jadi sebagai klaim situs itu
            // salah. Tempatnya di halaman custom, per produk.
            //
            // "Sebagian besar ready stock" boleh naik justru karena ada angka stok
            // nyata di tiap halaman produk yang mengoreksinya otomatis.
            'show_advantages'    => true,
            'advantages_heading' => 'Kenapa pilih Noud Acrylic?',
            'advantages' => [
                ['icon' => 'laser',   'title' => 'Dipotong mesin laser CNC',   'text' => 'Tepi potongan bening dan rapi tanpa perlu diamplas. Pesanan jumlah banyak ukurannya seragam.'],
                ['icon' => 'kotak',   'title' => 'Sebagian besar ready stock', 'text' => 'Siap kirim hari itu juga untuk pesanan sebelum pukul 12.00 WIB.'],
                ['icon' => 'jam',     'title' => 'Stok tampil apa adanya',     'text' => 'Angka stok di tiap halaman langsung dari sistem — bukan tulisan "tersedia" yang menipu.'],
                ['icon' => 'perisai', 'title' => 'Packing sesuai jarak kirim', 'text' => 'Bubble wrap, kardus, atau peti kayu. Anda pilih sesuai jarak dan jumlah.'],
                ['icon' => 'pabrik',  'title' => 'Produsen langsung',          'text' => 'Mesin dan workshop milik sendiri di Semarang sejak 2018. Bukan reseller — harga dan revisi langsung dari sumbernya.'],
            ],

            // Penyortir utama beranda: sebelas kategori berjajar terlalu banyak untuk
            // diproses dalam lima detik, dan tak satu pun memberi tahu pengunjung
            // bahwa toko ini untuk dirinya. Satu kategori sengaja boleh muncul di dua
            // kartu — pembeli berbeda mencari barang sama dengan kata berbeda.
            //
            // Kartu terakhir berbeda fungsi: menangkap orang yang barangnya memang
            // tidak ada di katalog, jadi tujuannya WhatsApp, bukan katalog.
            'show_segments'       => true,
            'segments_heading'    => 'Butuh akrilik untuk apa?',
            'segments_subheading' => 'Temukan produk sesuai kebutuhan Anda',
            'segments' => [
                [
                    'icon'  => 'koper',
                    'title' => 'Kantor & Instansi',
                    'text'  => 'Kotak saran, papan nama meja, tempat brosur, frame poster.',
                    'url'   => '/kategori/tempat-brosur',
                    'wa'    => '',
                ],
                [
                    'icon'  => 'toko',
                    'title' => 'Kafe, Toko & Resto',
                    'text'  => 'Tent card & stand QRIS, box charger, nama meja.',
                    'url'   => '/kategori/tent-cardholder',
                    'wa'    => '',
                ],
                [
                    'icon'  => 'megafon',
                    'title' => 'Promosi & Display',
                    'text'  => 'Tempat brosur, frame poster dinding, display informasi.',
                    'url'   => '/kategori/frame-poster',
                    'wa'    => '',
                ],
                [
                    'icon'  => 'pena',
                    'title' => 'Meja Kerja',
                    'text'  => 'Rak pulpen, tempat kartu nama, kotak tisu.',
                    'url'   => '/kategori/rak-pulpen',
                    'wa'    => '',
                ],
                [
                    'icon'  => 'hadiah',
                    'title' => 'Pernikahan & Momen',
                    'text'  => 'Box mahar & seserahan, bingkai foto.',
                    'url'   => '/kategori/box-mahar',
                    'wa'    => '',
                ],
                [
                    'icon'  => 'tag',
                    'title' => 'Custom Akrilik',
                    'text'  => 'Ukuran, bentuk, dan logo sesuai kebutuhan.',
                    'url'   => '',
                    'wa'    => 'Halo Noud Akrilik, saya ingin akrilik custom. Kebutuhan: ___ Ukuran: ___ Jumlah: ___',
                ],
            ],

            'show_categories'    => true,
            'categories_heading' => 'Semua kategori',

            'featured_heading' => 'Paling dicari pembeli kami',
            'featured_limit'   => 8,

            'show_savings'        => true,
            'savings_heading'     => 'Beli langsung di sini, lebih hemat',
            'savings_price_title' => 'Harga web lebih murah dari marketplace',
            'savings_price_text'  => 'Harga di website ini sudah dipotong dari harga marketplace. Tanpa kode, tanpa syarat minimum — potongannya langsung terlihat di setiap halaman produk.',
            'savings_ship_title'  => 'Potongan ongkir untuk pesanan besar',
            'savings_ship_text'   => 'Berlaku otomatis di checkout dan bertingkat: makin besar belanja, makin besar potongan ongkirnya.',
            'savings_link_label'  => 'Lihat ketentuan potongan ongkir',

            // Sorotan produk: barang bernilai pesanan tinggi diberi ruang penuh,
            // digeser satu per satu. Kartu di grid terlalu sempit untuk menjelaskan
            // kenapa box charger seharga jutaan itu masuk akal.
            //
            // Foto, nama, dan harga TIDAK diketik di sini — diambil dari Produk Store
            // lewat slug. Yang ditulis admin hanya sudut pandang penjualannya.
            //
            // Tiga produk ini dipilih karena mewakili tiga alasan berbeda: nilai
            // pesanan tertinggi, produk terlaris, dan kata kunci paling banyak dicari.
            'show_spotlight' => true,
            'spotlights' => [
                [
                    'slug'      => 'box-charger-hp-12-kotak',
                    'eyebrow'   => 'Solusi charging untuk kantor & instansi',
                    'heading'   => '',
                    'body'      => 'Charging station dengan 12 loker terpisah dan kunci masing-masing.',
                    'bullets'   => "Kunci berbeda tiap pintu, tidak bisa saling buka\nStop kontak sudah terpasang\nBisa ditempel di dinding atau diletakkan di meja\nLogo instansi dan jumlah pintu bisa disesuaikan",
                    'cta_label' => 'Lihat Detail Box Charger',
                ],
                [
                    'slug'      => 'kotak-saran-akrilik',
                    'eyebrow'   => 'Paling sering dipesan instansi',
                    'heading'   => '',
                    'body'      => 'Kotak saran akrilik bening dengan gembok, siap dipasang di dinding maupun diletakkan di meja.',
                    'bullets'   => "Akrilik bening, isi kotak terlihat penuh atau tidak\nBisa dikunci, tidak bisa dibuka sembarang orang\nNama dan logo instansi bisa dipasang\nDipakai puskesmas, kecamatan, sekolah, dan kantor pajak",
                    'cta_label' => 'Lihat Detail Kotak Saran',
                ],
                [
                    'slug'      => 'tent-card-akrilik-a5-tipe-t-2-sisi',
                    'eyebrow'   => 'Untuk kafe, toko, dan resto',
                    'heading'   => '',
                    'body'      => 'Tent card akrilik dua sisi — tempat menaruh QRIS, daftar menu, atau info promo di atas meja.',
                    'bullets'   => "Dua sisi, terbaca dari arah mana pun\nBerdiri sendiri, tidak perlu ditempel\nKertas isinya bisa diganti sendiri kapan saja\nTersedia ukuran A4, A5, dan A6",
                    'cta_label' => 'Lihat Detail Tent Card',
                ],
            ],

            // Galeri instansi. Fotonya TIDAK diunggah di sini — diambil dari foto
            // "Showcase" tiap Produk Store, supaya satu foto cukup diunggah sekali
            // dan tak pernah berbeda antara halaman produk dan beranda.
            'show_gallery'       => true,
            'gallery_heading'    => 'Dipercaya berbagai bisnis & instansi',
            'gallery_note'       => 'Produk kami terpasang di kantor pemerintah, puskesmas, sekolah, bank, kafe, dan perusahaan swasta.',
            'gallery_link_label' => '',
            'gallery_url'        => '',
            'gallery_limit'      => 8,

            'show_institution'      => true,
            'institution_heading'   => 'Pengadaan kantor, sekolah, atau instansi?',
            'institution_body'      => 'Kami melayani pembelian dalam jumlah banyak untuk kantor, sekolah, puskesmas, dan instansi pemerintah — lengkap dengan surat penawaran resmi dan faktur untuk keperluan administrasi.',
            'institution_bullets'   => [
                'Surat penawaran resmi & faktur',
                'Nama dan logo instansi bisa dipasang, warna penuh sesuai logo asli',
                'Stok besar bisa diproduksi 3–5 hari kerja',
                'Packing kayu untuk pengiriman aman ke luar kota',
            ],
            'institution_cta_label' => 'Minta Penawaran',
            'institution_cta_wa'    => 'Halo Noud Akrilik, saya ingin minta penawaran untuk pembelian instansi. Produk: ___ Jumlah: ___ Instansi: ___',

            'show_custom'       => true,
            'custom_heading'    => 'Bisa custom nama & logo instansi',
            // JANGAN menulis "grafir", "engraving", atau "cetak logo langsung di
            // produk": semua branding logo memakai stiker cetak. Jangan pula
            // menjelekkan stiker — itu menyerang metode sendiri.
            'custom_body'       => 'Nama dan logo instansi bisa dipasang di produk dengan stiker cetak berkualitas — warnanya mengikuti logo asli secara penuh, termasuk logo berwarna banyak atau bergradasi. Ukuran khusus juga bisa untuk sebagian produk. Kirimkan file logo dan kebutuhan Anda, kami bantu konsultasikan sebelum produksi.',
            'custom_cta_label'  => 'Konsultasi Custom',
            'custom_cta_wa'     => 'Halo Noud Akrilik, saya ingin custom nama/logo instansi. Produk: ___ Jumlah: ___',

            'show_workshop'    => true,
            'show_map'         => true,
            'workshop_heading' => 'Workshop kami di Semarang',
            'workshop_body'    => 'Bisa datang langsung untuk melihat dan memilih barang. Cek dulu stoknya di halaman produk — angkanya real-time dari sistem kami.',

            'show_faq'    => true,
            'faq_heading' => 'Pertanyaan yang sering diajukan',
            'faqs' => [
                ['q' => 'Apakah bisa custom ukuran dan logo?', 'a' => 'Bisa untuk sebagian produk. Untuk ukuran khusus, hubungi admin lewat WhatsApp — sebagian produk seperti tent card dan bingkai foto meja belum menerima custom ukuran.'],
                ['q' => 'Logo dipasang dengan cara apa?', 'a' => 'Menggunakan stiker berkualitas cetak, sehingga warna logo bisa mengikuti aslinya secara penuh — termasuk logo berwarna banyak atau bergradasi. Untuk pemakaian di luar ruangan atau area lembap, beri tahu kami agar bahan stikernya disesuaikan.'],
                ['q' => 'Berapa lama pengiriman?', 'a' => 'Produk ready stock dikirim di hari yang sama untuk pesanan sebelum jam 12.00 WIB. Produk yang perlu diproduksi memerlukan 3–5 hari kerja.'],
                ['q' => 'Apakah bisa beli satuan?', 'a' => 'Bisa. Kami melayani pembelian satuan sampai borongan.'],
                ['q' => 'Apakah menerima pesanan instansi dengan surat penawaran?', 'a' => 'Ya. Kami menyediakan surat penawaran resmi dan faktur untuk keperluan pengadaan.'],
                ['q' => 'Bagaimana keamanan pengiriman untuk barang pecah belah?', 'a' => 'Semua produk dikemas dengan bubble wrap dan kardus tebal. Untuk pengiriman jauh atau jumlah banyak, tersedia opsi packing kayu — gratis untuk pemesanan 6 pcs ke atas pada produk tertentu.'],
                ['q' => 'Apakah harga di website sama dengan marketplace?', 'a' => 'Harga di website ini lebih murah dari harga marketplace, dan ada potongan ongkir berjenjang yang berlaku otomatis di checkout.'],
            ],

            // Strip penutup sebelum footer. Isinya kepastian layanan (pembayaran,
            // jam CS, jangkauan kirim) — BUKAN pengulangan klaim produk di bagian
            // "Kenapa pilih Noud". Tanpa pembagian itu, keduanya jadi dua daftar
            // yang sama dan pembaca berhenti membaca keduanya.
            'show_trust'  => true,
            'trust_items' => [
                ['icon' => 'tag',     'title' => 'Harga di web lebih murah', 'text' => 'Dipotong dari harga marketplace'],
                ['icon' => 'jam',     'title' => 'Stok real-time',           'text' => 'Angkanya langsung dari sistem'],
                ['icon' => 'truk',    'title' => 'Potongan ongkir',          'text' => 'Otomatis di checkout, berjenjang'],
                ['icon' => 'kartu',   'title' => 'Pembayaran aman',          'text' => 'QRIS & transfer bank'],
                ['icon' => 'chat',    'title' => 'CS responsif',             'text' => 'Senin–Sabtu, 08.00–16.00 WIB'],
                ['icon' => 'bumi',    'title' => 'Kirim ke seluruh Indonesia', 'text' => 'Packing berjenjang sesuai jarak'],
            ],
        ];
    }

    /**
     * Bentuk JSON untuk etalase. Field kritis SEO (judul, H1, meta) jatuh ke teks
     * bawaan bila dikosongkan — beranda tanpa H1 adalah kerusakan yang tak terlihat
     * dari halaman admin, jadi jangan biarkan satu kotak kosong menyebabkannya.
     */
    public function toStorefrontArray(): array
    {
        $d = self::defaults();
        $req = fn (string $k) => filled($this->{$k}) ? $this->{$k} : ($d[$k] ?? null);

        return [
            'meta' => [
                'title'       => $req('meta_title'),
                'description' => $req('meta_description'),
                'og_image'    => $this->og_image_url,
            ],
            'hero' => [
                'eyebrow'         => $this->hero_eyebrow,
                'heading'         => $req('hero_heading'),
                'subheading'      => $this->hero_subheading,
                'primary_label'   => $req('hero_primary_label'),
                'primary_url'     => $req('hero_primary_url'),
                'secondary_label' => $this->hero_secondary_label,
                'secondary_wa'    => $this->hero_secondary_wa,
                'image_url'       => $this->hero_image_url,
                'image_alt'       => $this->hero_image_alt,
                'image_blend'     => (bool) $this->hero_image_blend,
                'badges'          => array_values($this->hero_badges ?? []),
            ],
            'advantages' => [
                'show'    => $this->show_advantages,
                'heading' => $this->advantages_heading,
                'items'   => array_values($this->advantages ?? []),
            ],
            'segments' => [
                'show'       => $this->show_segments,
                'heading'    => $this->segments_heading,
                'subheading' => $this->segments_subheading,
                'items'      => array_values($this->segments ?? []),
            ],
            'categories' => [
                'show'    => $this->show_categories,
                'heading' => $this->categories_heading,
            ],
            'featured' => [
                'heading' => $req('featured_heading'),
                'limit'   => max(1, (int) ($this->featured_limit ?: 8)),
            ],
            'savings' => [
                'show'        => $this->show_savings,
                'heading'     => $this->savings_heading,
                'price_title' => $this->savings_price_title,
                'price_text'  => $this->savings_price_text,
                'ship_title'  => $this->savings_ship_title,
                'ship_text'   => $this->savings_ship_text,
                'link_label'  => $this->savings_link_label,
            ],
            'spotlight' => [
                'show'  => $this->show_spotlight,
                // Slide yang slug produknya kosong dibuang di sini: tanpa slug,
                // etalase tak punya foto maupun tautan untuk ditampilkan.
                'items' => collect($this->spotlights ?? [])
                    ->filter(fn ($s) => filled($s['slug'] ?? null))
                    ->map(fn ($s) => [
                        'slug'      => trim($s['slug']),
                        'eyebrow'   => $s['eyebrow']   ?? null,
                        'heading'   => $s['heading']   ?? null,
                        'body'      => $s['body']      ?? null,
                        'cta_label' => $s['cta_label'] ?? null,
                        // Satu manfaat per baris — bentuk isian yang paling mudah
                        // di repeater, dipecah di sini supaya etalase terima larik.
                        'bullets'   => collect(preg_split('/\r\n|\r|\n/', (string) ($s['bullets'] ?? '')))
                            ->map(fn ($b) => trim($b))->filter()->values()->all(),
                    ])->values()->all(),
            ],
            // `items` diisi controller dari media Showcase produk — bukan di sini,
            // supaya model tetap murni pengaturan dan tidak menyentuh query lain.
            'gallery' => [
                'show'       => $this->show_gallery,
                'heading'    => $this->gallery_heading,
                'note'       => $this->gallery_note,
                'link_label' => $this->gallery_link_label,
                'url'        => $this->gallery_url,
                'limit'      => max(1, (int) ($this->gallery_limit ?: 8)),
                'items'      => [],
            ],
            'institution' => [
                'show'      => $this->show_institution,
                'heading'   => $this->institution_heading,
                'body'      => $this->institution_body,
                'bullets'   => array_values($this->institution_bullets ?? []),
                'cta_label' => $this->institution_cta_label,
                'cta_wa'    => $this->institution_cta_wa,
            ],
            'custom' => [
                'show'      => $this->show_custom,
                'heading'   => $this->custom_heading,
                'body'      => $this->custom_body,
                'cta_label' => $this->custom_cta_label,
                'cta_wa'    => $this->custom_cta_wa,
            ],
            'workshop' => [
                'show'     => $this->show_workshop,
                'show_map' => $this->show_map,
                'heading'  => $this->workshop_heading,
                'body'     => $this->workshop_body,
            ],
            'faq' => [
                'show'    => $this->show_faq,
                'heading' => $this->faq_heading,
                'items'   => array_values($this->faqs ?? []),
            ],
            'trust' => [
                'show'  => $this->show_trust,
                'items' => array_values($this->trust_items ?? []),
            ],
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
