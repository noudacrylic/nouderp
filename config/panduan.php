<?php

/*
|--------------------------------------------------------------------------
| Panduan Penggunaan (in-app)
|--------------------------------------------------------------------------
| Dikelompokkan per peran/tugas karyawan. Tiap kategori berisi beberapa
| panduan langkah demi langkah (task-oriented), bahasa sederhana.
|
| Struktur:
|   'kategori_key' => [
|       'label' => ..., 'icon' => ..., 'desc' => ...,
|       'menus' => [menu_key terkait]  // untuk tandai "Untuk Anda"
|       'guides' => [
|           ['title'=>..., 'intro'=>..., 'steps'=>[...], 'tips'=>[...]]
|       ],
|   ]
|
| Tambah/ubah panduan cukup edit array di bawah — tidak perlu sentuh kode.
*/

return [

    'operasional' => [
        'label' => 'Operasional Harian',
        'icon'  => '🛒',
        'desc'  => 'Kasir, penjualan, dan cek stok untuk pekerjaan sehari-hari.',
        'menus' => ['pos', 'sales', 'inventory'],
        'guides' => [
            [
                'title' => 'Transaksi di Kasir (POS)',
                'intro' => 'Untuk penjualan langsung di toko — pembeli bayar saat itu juga. Tidak perlu buat SO; langsung jadi faktur.',
                'steps' => [
                    'Buka menu POS → Kasir.',
                    'Cari produk lewat kotak pencarian (ketik SKU atau nama), klik untuk menambah ke keranjang. Ulangi untuk semua barang.',
                    'Atur jumlah (qty) tiap barang bila perlu. Total terhitung otomatis.',
                    'Pilih metode bayar: Tunai (Cash) atau QRIS.',
                    'Untuk tunai: ketik nominal uang pembeli → sistem menghitung kembalian.',
                    'Klik Bayar/Selesai. Faktur otomatis dibuat dan uang masuk ke kas.',
                    'Cetak struk bila diperlukan.',
                ],
                'tips' => [
                    'Pelanggan tanpa nama cukup pakai pelanggan "Umum".',
                    'Barang diambil langsung (ambil di toko), jadi tidak perlu Surat Jalan.',
                ],
            ],
            [
                'title' => 'Memproses Pesanan (Pemrosesan Pesanan POS)',
                'intro' => 'Untuk menyiapkan & mengirim pesanan yang sudah masuk (dari marketplace / SO), per mode pengiriman.',
                'steps' => [
                    'Buka POS → Pemrosesan Pesanan.',
                    'Pilih tab sesuai cara kirim (mis. dikirim/ambil sendiri).',
                    'Tiap kartu pesanan menampilkan qty Dipesan / Dikirim / Belum.',
                    'Centang pesanan yang sudah disiapkan, lalu klik Proses (bisa sekaligus banyak).',
                    'Cetak label/dokumen gabungan untuk pesanan yang diproses.',
                ],
                'tips' => [
                    'Catatan penjual bisa diisi langsung di kartu.',
                    'Klik nomor pesanan untuk menyalinnya.',
                ],
            ],
            [
                'title' => 'Penjualan: Penawaran → SO → Faktur',
                'intro' => 'Alur penjualan resmi untuk pesanan yang butuh penawaran/pengiriman.',
                'steps' => [
                    'Buat Penawaran (Sales → Penawaran → + Tambah). Pilih pelanggan, tambah item, simpan.',
                    'Bila disetujui pelanggan, ubah Penawaran jadi Sales Order (SO).',
                    'Konfirmasi SO. Untuk barang dikirim, isi alamat & pilih kurir di kartu Pengiriman (cek ongkir otomatis).',
                    'Buat Surat Jalan saat barang dikirim (boleh sebagian/partial).',
                    'Buat Faktur (Invoice) untuk menagih. Catat pembayaran saat pelanggan bayar.',
                ],
                'tips' => [
                    'Nama item bisa diedit langsung (untuk barang custom).',
                    'Diskon bisa per-item atau global; isi sesuai kesepakatan.',
                ],
            ],
            [
                'title' => 'Cek Stok & Ubah Harga Produk',
                'intro' => 'Melihat sisa stok dan memperbarui harga jual dengan cepat.',
                'steps' => [
                    'Buka Inventory → Stok untuk melihat stok fisik, dipesan, tersedia per produk.',
                    'Gunakan kotak pencarian (ketik SKU/nama) — hasil muncul tanpa reload.',
                    'Untuk ubah harga: Inventory → Produk, ketik harga baru di kolom Harga, lalu klik tombol simpan (✓) atau tekan Enter.',
                    'Untuk produk baru: Produk → + Tambah Produk, isi data, simpan.',
                ],
                'tips' => [
                    'Centang "Dijual" agar produk muncul di Kasir/POS/Penawaran. Lepas centang untuk produk yang hanya dipakai produksi.',
                    'Produk diarsipkan tidak muncul di pencarian transaksi.',
                ],
            ],
        ],
    ],

    'produksi' => [
        'label' => 'Produksi',
        'icon'  => '🏭',
        'desc'  => 'Dari order produksi, pengerjaan di lapangan, sampai barang jadi masuk stok.',
        'menus' => ['production.boms', 'production.orders', 'production.process', 'production.additions', 'production.finalization'],
        'guides' => [
            [
                'title' => 'Membuat Order Produksi (OP)',
                'intro' => 'Perintah untuk memproduksi barang, dari resep (BOM) atau custom.',
                'steps' => [
                    'Buka Produksi → Order Produksi → + Buat Order Produksi.',
                    'Pilih tipe: Ready Stock (pakai BOM) atau Custom/Preorder.',
                    'Pilih BOM (resep) dan isi jumlah siklus/qty yang akan diproduksi.',
                    'Periksa daftar bahan baku & output yang muncul otomatis dari BOM.',
                    'Simpan. OP berstatus Draft.',
                    'Klik Konfirmasi & Mulai Produksi — bahan baku otomatis keluar dari stok dan dicatat ke WIP.',
                ],
                'tips' => [
                    'Untuk produk custom, ada tombol Kalkulator Produk Custom untuk menghitung kebutuhan bahan dari ukuran lembar.',
                    'Batal: OP yang sudah dikonfirmasi masih bisa dibatalkan SELAMA belum mulai dikerjakan (masih antre di langkah pertama). Material akan dikembalikan ke stok.',
                ],
            ],
            [
                'title' => 'Mengerjakan Produksi (Proses Produksi)',
                'intro' => 'Operator memulai/menyelesaikan tiap langkah produksi, waktu kerja dihitung otomatis dari scan fingerprint.',
                'steps' => [
                    'Buka Produksi → Proses Produksi. Pilih divisi bila perlu.',
                    'Cari kartu task yang akan dikerjakan (urut prioritas).',
                    'Operator scan fingerprint untuk memulai langkah — timer berjalan.',
                    'Saat langkah selesai, scan lagi / tandai selesai. Task pindah ke langkah berikutnya.',
                    'Setelah semua langkah selesai, OP siap difinalisasi.',
                ],
                'tips' => [
                    'Mesin (eksekutor anak) ikut waktu operator yang scan.',
                    'Beberapa task dengan resep & langkah sama bisa digabung agar sekali kerja.',
                ],
            ],
            [
                'title' => 'Penambahan Bahan di Tengah Produksi',
                'intro' => 'Untuk mengganti komponen rusak atau menambah bahan saat produksi berjalan.',
                'steps' => [
                    'Buka Produksi → Penambahan Bahan → Tambah Baru.',
                    'Pilih divisi lalu pilih Task Produksi yang sedang berjalan.',
                    'Tambah baris bahan baku + jumlah yang dipakai.',
                    'Bila ada pengeluaran kas (mis. beli bahan dadakan), tambahkan di Biaya Tambahan.',
                    'Isi catatan (alasan/nomor laporan kerusakan), lalu Simpan.',
                ],
                'tips' => [
                    'Stok bahan langsung berkurang saat disimpan dan dicatat ke jurnal WIP.',
                ],
            ],
            [
                'title' => 'Finalisasi Produksi',
                'intro' => 'Mencatat hasil produksi jadi (output) supaya masuk stok dan biaya tertutup.',
                'steps' => [
                    'Buka Produksi → Finalisasi (atau dari OP yang sudah selesai dikerjakan).',
                    'Isi jumlah output yang benar-benar jadi per produk.',
                    'Untuk produk sampingan, persentase/alokasi biaya terhitung otomatis.',
                    'Klik Finalisasi. Output masuk stok dan jurnal HPP dicatat.',
                ],
                'tips' => [
                    'Kalau hasil belum siap/stok kurang, status jadi "Menunggu Stok" dan bisa difinalisasi ulang nanti.',
                ],
            ],
            [
                'title' => 'Bill of Materials (BOM) & Auto-Produksi',
                'intro' => 'Resep produk: bahan, output, dan langkah. Bisa otomatis membuat OP saat stok menipis.',
                'steps' => [
                    'Buka Produksi → Bill of Materials → + Buat BOM.',
                    'Isi nama, bahan baku (qty per siklus), output (utama + sampingan), dan langkah/divisi.',
                    'Atur Jumlah Siklus Biasa (bisa diedit cepat langsung di tabel daftar BOM).',
                    'Aktifkan tombol ON di kolom Auto agar sistem otomatis membuat OP saat stok produk menipis.',
                    'Tombol "Jalankan Auto Produksi" untuk memicu pengecekan manual.',
                ],
                'tips' => [
                    'Produk utama hanya boleh 1 per BOM; sisanya produk sampingan.',
                ],
            ],
        ],
    ],

    'sdm' => [
        'label' => 'SDM & Absensi',
        'icon'  => '👥',
        'desc'  => 'Absensi sidik jari, periode, slip gaji, dan data karyawan.',
        'menus' => ['sdm.absensi', 'sdm.karyawan', 'sdm.fingerprint-machines'],
        'guides' => [
            [
                'title' => 'Bagaimana Absensi Terisi (Otomatis)',
                'intro' => 'Absensi harian terisi sendiri dari scan fingerprint — tidak perlu input manual.',
                'steps' => [
                    'Karyawan scan sidik jari di mesin saat datang & pulang.',
                    'Mesin mengirim data ke ERP; baris absensi hari itu (Datang/Pulang) terisi otomatis.',
                    'Buka SDM → Absensi untuk melihat. Pilih Periode (bulan) dan nama karyawan di atas.',
                    'Periode bulan berjalan dibuat otomatis; tidak perlu setting manual.',
                ],
                'tips' => [
                    'Kalau scan sudah masuk tapi absensi belum terisi: buka Mesin Fingerprint → tombol Sinkron sekali.',
                    'Sidik jari yang belum terdaftar akan muncul "tidak match" — daftarkan dulu di data karyawan.',
                ],
            ],
            [
                'title' => 'Upload Excel Absensi (Cadangan)',
                'intro' => 'Hanya kalau mesin bermasalah / baru ganti mesin sehingga data 1 periode tidak lengkap.',
                'steps' => [
                    'Buka SDM → Absensi, pilih periode yang dimaksud.',
                    'Klik Upload Excel, pilih file sesuai format.',
                    'Excel hanya mengisi slot yang masih kosong — data dari mesin tetap diutamakan.',
                ],
                'tips' => [
                    'Excel adalah cadangan; sumber utama tetap scan fingerprint.',
                ],
            ],
            [
                'title' => 'Slip Gaji: Generate & Cetak',
                'intro' => 'Membuat slip gaji per karyawan dari data absensi, lalu mencetaknya.',
                'steps' => [
                    'Buka SDM → Absensi → tab Periode, lalu buka periode yang diinginkan (Detail).',
                    'Klik tombol Generate Slip — slip semua karyawan dibuat dari data absensi (tanpa Excel).',
                    'Periksa slip per karyawan (klik baris untuk detail).',
                    'Cetak per orang, atau "Cetak Semua" / "PDF Semua".',
                ],
                'tips' => [
                    'Generate Slip aman diulang; slip yang sudah ada diperbarui sesuai absensi terkini.',
                    'Status periode "Berjalan" artinya masih bisa diisi/diedit; "Final" artinya terkunci.',
                ],
            ],
            [
                'title' => 'Membayar Gaji',
                'intro' => 'Pembayaran gaji dicatat ke Kas & Bank.',
                'steps' => [
                    'Di SDM → Absensi, pilih karyawan, periksa ringkasan (Gaji/Hari, Lembur, dll) yang dihitung dari absensi.',
                    'Klik tombol Bayar.',
                    'Pilih akun kas/bank sumber pembayaran, periksa potongan (BPJS, PPh21, kasbon) bila ada.',
                    'Simpan/Posting — pengeluaran tercatat di Kas & Bank.',
                ],
                'tips' => [
                    'Angka diambil otomatis dari absensi — tidak perlu hitung manual.',
                ],
            ],
            [
                'title' => 'Mengelola Karyawan',
                'intro' => 'Menambah karyawan, jadwal kerja, dan ID sidik jari.',
                'steps' => [
                    'Buka SDM → Karyawan → + Tambah (atau klik karyawan untuk edit).',
                    'Isi data lewat tab-tab: identitas, gaji, jadwal mingguan, dll.',
                    'Isi ID sidik jari (user_id_fingerprint) agar scan terhubung ke karyawan ini.',
                    'Simpan.',
                ],
                'tips' => [
                    'Jadwal mingguan menentukan hari kerja/libur untuk perhitungan gaji.',
                ],
            ],
        ],
    ],

    'pembelian_keuangan' => [
        'label' => 'Pembelian & Keuangan',
        'icon'  => '💰',
        'desc'  => 'Pembelian dari pemasok, retur, kas & bank, dan laporan.',
        'menus' => ['purchasing.orders', 'purchasing.suppliers', 'cash_bank', 'accounting.coa', 'reports.balance-sheet'],
        'guides' => [
            [
                'title' => 'Pembelian: PO → Faktur → Pembayaran',
                'intro' => 'Alur membeli barang dari pemasok sampai lunas.',
                'steps' => [
                    'Buka Purchasing → Purchase Order → + Tambah.',
                    'Pilih pemasok (bisa tambah pemasok baru lewat tombol + di sebelah kolom), gudang tujuan, dan tanggal.',
                    'Tambah item (cari SKU/nama), isi qty, harga, diskon bila ada.',
                    'Simpan & konfirmasi PO.',
                    'Saat barang datang, buat Faktur Pembelian (Purchase Invoice) — stok bertambah.',
                    'Catat Pembayaran ke pemasok saat membayar.',
                ],
                'tips' => [
                    'Untuk pembelian aset/non-katalog: centang Aset, kosongkan SKU, ketik nama langsung.',
                    'Biaya tambahan (ongkir, dll) bisa Capitalized (nambah HPP) atau Direct Expense (langsung beban).',
                ],
            ],
            [
                'title' => 'Retur Pembelian',
                'intro' => 'Mengembalikan barang ke pemasok.',
                'steps' => [
                    'Buka Purchasing → Return → + Tambah.',
                    'Pilih pemasok dan dokumen sumber (PO/Faktur).',
                    'Pilih item & jumlah yang diretur.',
                    'Simpan & posting — stok berkurang dan saldo utang/uang muka disesuaikan.',
                ],
                'tips' => [],
            ],
            [
                'title' => 'Kas & Bank: Pemasukan & Pengeluaran',
                'intro' => 'Mencatat uang masuk dan keluar di luar penjualan/pembelian rutin.',
                'steps' => [
                    'Buka menu Kas & Bank.',
                    'Untuk uang keluar: Pengeluaran → pilih jenis (umum, ongkir, dll), isi akun beban, nominal, dan akun kas sumber.',
                    'Untuk uang masuk: Pemasukan → isi sumber, nominal, dan akun kas tujuan.',
                    'Simpan/Posting — jurnal otomatis tercatat.',
                ],
                'tips' => [
                    'Semua transaksi kas memakai akun kas/bank yang sudah terdaftar.',
                ],
            ],
            [
                'title' => 'Melihat Laporan Keuangan',
                'intro' => 'Neraca, Laba Rugi, dan laporan penjualan.',
                'steps' => [
                    'Buka menu Laporan.',
                    'Pilih Neraca (posisi aset/utang/modal) atau Laba Rugi (untung/rugi periode).',
                    'Atur rentang tanggal yang diinginkan.',
                    'Untuk penjualan per produk, buka Laporan Penjualan.',
                ],
                'tips' => [
                    'Pastikan transaksi sudah diposting agar muncul di laporan.',
                ],
            ],
        ],
    ],

];
