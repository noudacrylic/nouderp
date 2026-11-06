<?php

/*
| Skill Manajer Admin "Prioritas & pengganti admin" (admin.prioritas) — diputuskan 6 Nov 2026.
|
| URUTAN: kartu di sebuah meja diurutkan per KATEGORI (atas → bawah); di dalam satu kategori,
| tenggat terdekat lebih dulu, lalu yang lebih lama menunggu. Kategori = kunci skill Meja Kerja,
| ditambah kategori khusus:
|   retur-24j    retur "terima atau banding" yang batas Shopee-nya < 24 jam lagi
|   retur        retur lainnya (finish retur: hasil final, manual, periksa yang batasnya masih lama)
|   owner-utama / owner-normal / owner-rendah   Tugas dari Owner sesuai prioritas pilihan owner
| Kategori yang tak disebut (skill baru) masuk sebelum owner-rendah, urut bobot skill.
| Kartu pindahan (oper) ikut diurutkan menurut meja PENERIMA — karena itu kategori milik meja
| lain ikut disebut di sini.
*/

return [
    'urutan' => [
        'layanan' => [
            'owner-utama',
            'desain',               // hanya ada saat dioper dari admin desain yang tidak masuk — prioritas utama
            'retur-24j',
            'owner-normal',
            'qc',
            'purchasing-laporan', 'purchasing-ready', 'purchasing-nonstok',
            'selesaikan-pesanan',
            'retur',
            'ulasan-jelek',
            'owner-rendah',
        ],
        'desain' => [
            'owner-utama',
            'retur-24j',            // dioper dari admin layanan — prioritas utama
            'desain',
            'qc',                   // dioper dari admin layanan — setelah desain
            'owner-normal',
            'desain-gambar', 'foto-custom',
            'cetak-stiker',
            'owner-rendah',
        ],
    ],

    /*
    | PENGGANTI: admin meja `dari` tidak berangkat → semua chat CRM yang dipegangnya dan kartu
    | kategori `kartu` dioper ke anggota meja `ke` yang hadir. Kartu lain tetap menunggunya.
    | Begitu ia hadir (scan fingerprint), yang dioper dikembalikan.
    */
    'pengganti' => [
        'layanan' => ['ke' => 'desain', 'kartu' => ['retur-24j', 'qc']],
        'desain'  => ['ke' => 'layanan', 'kartu' => ['desain']],
    ],

    // Tidak berangkat = izin/cuti seharian disetujui, atau belum scan fingerprint sampai jam ini.
    'jam_batas_scan' => '09:00',
    'izin_seharian'  => ['cuti', 'sakit', 'tukar_hari'],
];
