<?php

/*
| Balas ulasan bagus Shopee (⭐4–5) — skill Manajer Admin `admin.ulasan-bagus`.
| Ulasan tanpa tulisan dibalas dengan template di bawah (diputar per ulasan supaya halaman
| produk tidak penuh kalimat yang sama); ulasan bertulisan dibalas AI, dan yang menyelipkan
| keluhan diserahkan ke kartu "Balas ulasan" di Meja Layanan.
|
| {produk} = nama barang pesanan (hanya dipakai bila pesanannya satu jenis barang; selain itu
| template tanpa {produk} yang dipilih).
*/

return [
    'template' => [
        'Terima kasih banyak atas bintangnya, Kak! Senang sekali {produk} sudah sampai dengan baik. Ditunggu pesanan berikutnya di Noud Acrylic ya 🙏',
        'Halo Kak, terima kasih sudah belanja di Noud Acrylic dan memberi penilaian yang baik. Semoga {produk}-nya awet dan bermanfaat ya 😊',
        'Terima kasih Kak atas kepercayaannya berbelanja di Noud Acrylic. Ulasan Kakak sangat berarti untuk kami. Sampai jumpa di pesanan berikutnya!',
        'Wah, terima kasih banyak untuk bintangnya, Kak! Kami senang bisa melayani Kakak. Kalau butuh produk akrilik lainnya, Noud Acrylic siap bantu ya 🙏',
        'Terima kasih Kak sudah memilih {produk} dari Noud Acrylic. Semoga makin rapi dan nyaman dipakai setiap hari ya 😊',
        'Halo Kak, terima kasih atas ulasan positifnya! Dukungan Kakak bikin kami makin semangat membuat produk akrilik yang berkualitas. Ditunggu kedatangannya kembali ya.',
        'Terima kasih banyak, Kak 🙏 Senang pesanannya sudah diterima dengan baik. Jangan ragu chat kami kalau ada yang bisa dibantu ya.',
        'Makasih banyak atas penilaiannya, Kak! Semoga {produk} menemani aktivitas Kakak dengan baik. Salam hangat dari tim Noud Acrylic 😊',
    ],

    // Hanya ulasan sekian hari terakhir yang dibalas — ulasan lama tidak dibanjiri balasan sekaligus.
    'jendela_hari' => 7,

    // Batas balasan per jalan (tiap 15 menit) supaya tidak menembak API Shopee beruntun.
    'batas_per_jalan' => 30,
];
