<?php

/*
 * Peraturan kerja yang ditampilkan di PWA Karyawan (/me/peraturan).
 * Sumber: Perjanjian Kerja Waktu Tidak Tertentu Noud Acrylic Shop (PDF).
 * Isian pribadi (nama, gaji pokok, nominal tunjangan makan, tanggal mulai)
 * sengaja tidak ditulis di sini — mengacu ke Perjanjian Kerja masing-masing.
 * Pasal identitas para pihak & tanda tangan (Pasal 17) tidak ditampilkan.
 *
 * Tiap ayat berupa string, atau ['text' => ..., 'sub' => [...]] untuk butir turunan.
 */

return [
    'judul'     => 'Peraturan Kerja Noud Acrylic Shop',
    'sumber'    => 'Perjanjian Kerja Waktu Tidak Tertentu',

    'pasal' => [
        [
            'no'    => 1,
            'judul' => 'Jabatan dan Tempat Kerja',
            'ayat'  => [
                'Karyawan dipekerjakan sesuai jabatan yang tercantum dalam Perjanjian Kerja di Noud Acrylic Shop, Banyumanik, Semarang.',
                'Karyawan wajib melaksanakan tugas yang diberikan Pemilik dengan penuh tanggung jawab serta mematuhi peraturan kerja yang berlaku.',
            ],
        ],
        [
            'no'    => 2,
            'judul' => 'Jangka Waktu dan Masa Percobaan',
            'ayat'  => [
                'Perjanjian berlaku sejak tanggal yang tercantum dalam Perjanjian Kerja untuk waktu tidak tertentu.',
                'Karyawan menjalani masa percobaan paling lama 3 bulan terhitung sejak tanggal mulai bekerja.',
            ],
        ],
        [
            'no'    => 3,
            'judul' => 'Gaji dan Hari Kerja',
            'ayat'  => [
                'Gaji Pokok per bulan dan tanggal pembayarannya sesuai yang tercantum dalam Perjanjian Kerja.',
                'Gaji per hari dihitung dari Gaji Pokok per bulan dibagi jumlah hari kerja dalam bulan tersebut.',
                'Tanggal merah dan hari libur mingguan tidak dihitung sebagai hari kerja.',
                'Upah per jam untuk perhitungan lembur adalah Gaji Pokok per bulan dibagi 173.',
            ],
        ],
        [
            'no'    => 4,
            'judul' => 'Tunjangan Makan Siang',
            'ayat'  => [
                'Karyawan berhak atas Tunjangan Makan Siang (nominal sesuai Perjanjian Kerja) untuk setiap hari kerja yang dijalani penuh.',
                'Tunjangan Makan Siang tidak diberikan dalam keadaan sebagaimana diatur dalam Pasal 6 dan Pasal 7.',
            ],
        ],
        [
            'no'    => 5,
            'judul' => 'Jam Kerja',
            'ayat'  => [
                ['text' => 'Jam kerja berlaku mulai Januari 2025 sebagai berikut:', 'sub' => [
                    'Masuk: 08.00',
                    'Istirahat: 11.30 – 12.30',
                    'Pulang: 16.00',
                ]],
            ],
        ],
        [
            'no'    => 6,
            'judul' => 'Keterlambatan dan Pulang Awal',
            'ayat'  => [
                'Scan sidik jari di atas pukul 08.10 dianggap terlambat.',
                'Scan sidik jari masuk setelah pukul 10.30 dihitung sebagai setengah hari.',
                'Scan sidik jari pulang sebelum pukul 16.00 dianggap pulang lebih awal.',
                'Scan sidik jari pulang sebelum pukul 14.00 dihitung sebagai setengah hari.',
                'Terlambat atau pulang lebih awal tidak mendapatkan Tunjangan Makan Siang.',
            ],
        ],
        [
            'no'    => 7,
            'judul' => 'Izin Setengah Hari dan Sakit',
            'ayat'  => [
                'Izin setengah hari harus diajukan paling lambat H-2, dihitung tidak masuk, dan tidak mendapatkan Tunjangan Makan Siang.',
                'Karyawan yang sakit tetap mendapatkan Gaji Pokok tetapi tidak mendapatkan Tunjangan Makan Siang.',
                'Izin sakit wajib disertai surat keterangan dokter.',
            ],
        ],
        [
            'no'    => 8,
            'judul' => 'Cuti',
            'ayat'  => [
                'Cuti mengikuti ketentuan peraturan perundang-undangan yang berlaku.',
                'Hak cuti tahunan adalah 12 (dua belas) hari kerja.',
                'Hak cuti diberikan setelah karyawan bekerja minimal 1 (satu) tahun secara terus-menerus dan dihitung setiap awal tahun.',
                'Permohonan cuti diajukan paling lambat H-7.',
                'Cuti pada hari kerja yang berada di antara dua hari libur mengurangi hak cuti 1 (satu) hari.',
                'Hak cuti yang tidak diambil sampai akhir tahun diganti uang sebesar Rp100.000 per hari dan dibayarkan di akhir tahun.',
            ],
        ],
        [
            'no'    => 9,
            'judul' => 'Ganti Hari dan Lembur',
            'ayat'  => [
                'Hari kerja dapat ditukar dengan hari libur dengan persetujuan Pemilik paling lambat H-2.',
                'Lembur dilakukan atas perintah Pemilik, atau atas pengajuan karyawan dengan persetujuan paling lambat H-1.',
                'Upah lembur dihitung 2 (dua) kali upah per jam sebagaimana dimaksud Pasal 3 ayat 4.',
                'Jadwal lembur hari kerja: mulai 16.30, istirahat 18.00 – 18.30, selesai paling lambat 20.00.',
                'Jadwal lembur hari libur sama dengan jadwal hari kerja.',
                'Lembur di hari libur dapat menggantikan hari absen apabila keesokan harinya karyawan tidak masuk atau cuti.',
                'Karyawan yang diperintahkan lembur tetapi tidak hadir dikenakan sanksi yang sama dengan tidak masuk kerja.',
            ],
        ],
        [
            'no'    => 10,
            'judul' => 'Tunjangan Bulanan dan Surat Peringatan (SP)',
            'ayat'  => [
                ['text' => 'Tunjangan Bulanan diberikan berdasarkan status SP sebagai berikut:', 'sub' => [
                    'SP0: Rp200.000 per bulan',
                    'SP1: Rp100.000 per bulan',
                    'SP2 dan SP3: tidak mendapatkan Tunjangan Bulanan',
                ]],
                'Setiap SP berlaku selama 6 (enam) bulan sejak diterbitkan.',
                'Karyawan yang menerima SP3 menjalani masa evaluasi 1 (satu) bulan dan dapat diberhentikan.',
                ['text' => 'Sanksi ditentukan oleh Pemilik dengan mempertimbangkan hal berikut:', 'sub' => [
                    'Absen tanpa izin: naik 1 (satu) tingkat SP.',
                    'Terlambat lebih dari 50% hari kerja dalam 1 (satu) bulan: dipertimbangkan naik 1 (satu) tingkat SP.',
                    'Menimbulkan kerugian perusahaan: dikenai sanksi sesuai tingkat kerugian.',
                    'Membocorkan rahasia perusahaan atau mencemarkan nama baik perusahaan: langsung dikenai SP3 atau diberhentikan.',
                    'Melakukan tindak pidana: diproses sesuai hukum dan langsung diberhentikan.',
                ], 'abjad' => true],
            ],
        ],
        [
            'no'    => 11,
            'judul' => 'Pemutusan Hubungan Kerja (PHK)',
            'ayat'  => [
                'Pesangon dihitung sebesar lama kerja (dalam tahun) dikali Gaji Pokok bulanan, paling banyak 6 (enam) kali Gaji Pokok.',
                'Pesangon tidak diberikan kepada karyawan yang mengundurkan diri atas kemauan sendiri.',
                'Pesangon tidak diberikan kepada karyawan yang diberhentikan karena pelanggaran sebagaimana diatur dalam Pasal 10.',
                'Karyawan yang akan mengundurkan diri wajib mengajukan pengunduran diri kepada Pemilik paling lambat 1 (satu) bulan sebelum tanggal berhenti, agar Pemilik dapat mencari pengganti.',
                'Apabila pengganti sudah didapatkan sebelum 1 (satu) bulan, karyawan boleh berhenti lebih awal. Apabila sampai 1 (satu) bulan pengganti belum didapatkan, karyawan tetap boleh berhenti.',
            ],
        ],
        [
            'no'    => 12,
            'judul' => 'Penggunaan Perangkat Selama Bekerja',
            'ayat'  => [
                'Dilarang menggunakan HP atau perangkat lain untuk hal yang mengganggu pekerjaan, seperti menonton film, menonton reels, atau menggulir media sosial.',
                'Diperbolehkan mendengarkan musik, ceramah, murotal, atau podcast menggunakan headset.',
            ],
        ],
        [
            'no'    => 13,
            'judul' => 'Kebersihan Tempat Kerja',
            'ayat'  => [
                'Karyawan wajib membersihkan area kerja masing-masing setiap hari.',
                'Area kerja tidak boleh ditinggalkan dalam keadaan kotor.',
                'Karyawan wajib ikut serta dalam kegiatan bersih-bersih umum apabila diminta.',
                'Pelanggaran atas pasal ini dikenakan sanksi sesuai kebijakan Pemilik.',
            ],
        ],
        [
            'no'    => 14,
            'judul' => 'Ketentuan Khusus Penghuni Mess',
            'ayat'  => [
                'Penghuni mess wajib menjaga kebersihan, ketertiban, dan keamanan mess.',
                'Area yang wajib dibersihkan meliputi tempat tidur, depan kamar, kamar mandi, dapur, mushola, dan halaman.',
                'Pembersihan dilakukan sesuai jadwal atau secara bergiliran.',
                'Dilarang membawa tamu menginap tanpa izin Pemilik.',
                'Listrik dan air wajib digunakan secara hemat dan bertanggung jawab.',
                'Kerusakan akibat kelalaian menjadi tanggung jawab penghuni.',
                'Pelanggaran atas pasal ini dapat dikenakan sanksi hingga dikeluarkan dari mess.',
            ],
        ],
        [
            'no'    => 15,
            'judul' => 'Ketentuan Lain',
            'ayat'  => [
                'Hal-hal yang belum diatur dalam perjanjian ini mengikuti peraturan perundang-undangan ketenagakerjaan yang berlaku.',
                'Perubahan atas perjanjian ini hanya sah apabila dibuat secara tertulis dalam bentuk addendum dan ditandatangani kedua belah pihak.',
            ],
        ],
        [
            'no'    => 16,
            'judul' => 'Penyelesaian Perselisihan',
            'ayat'  => [
                'Perselisihan yang timbul dari perjanjian ini diselesaikan terlebih dahulu secara musyawarah untuk mufakat.',
                'Apabila musyawarah tidak tercapai, penyelesaian dilakukan sesuai mekanisme penyelesaian perselisihan hubungan industrial yang berlaku.',
            ],
        ],
    ],
];
