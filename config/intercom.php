<?php

/*
|--------------------------------------------------------------------------
| Intercom Divisi
|--------------------------------------------------------------------------
|
| Satu perangkat = satu stasiun (divisi). Stasiun dipilih di perangkatnya
| sendiri dan disimpan di peramban, jadi siapa pun yang login di perangkat
| itu tetap "Packing" bila perangkatnya stasiun Packing.
|
| Kunci stasiun dipakai di sinyal & log — jangan diganti setelah dipakai,
| cukup ubah labelnya.
|
*/

return [

    // Intercom hanya tampil bila Reverb sudah dikonfigurasi.
    'enabled' => env('INTERCOM_ENABLED', true) && filled(env('REVERB_APP_KEY')),

    'stasiun' => [
        'admin'     => ['label' => 'Admin',     'ikon' => '🗂️'],
        'packing'   => ['label' => 'Packing',   'ikon' => '📦'],
        'cnc'       => ['label' => 'CNC',       'ikon' => '⚙️'],
        'asembling' => ['label' => 'Asembling', 'ikon' => '🔧'],
    ],

    // Panggilan langsung tersambung tanpa diangkat. Stasiun yang menyalakan
    // "Jangan Ganggu" tetap berdering dan harus diangkat manual.
    'nada_sambung_ms' => 1200,

    // Saluran ditutup otomatis bila kedua pihak diam selama ini (detik),
    // supaya mikrofon tidak terbuka terus karena lupa menutup.
    'putus_hening_detik' => 20,

    // Batas keras satu panggilan (detik). Mesin CNC yang bising bisa membuat
    // pemantau hening tidak pernah melihat "diam".
    'maks_durasi_detik' => 600,

    // Batas menunggu stasiun "Jangan Ganggu" mengangkat (detik).
    'batas_dering_detik' => 30,

    // Server STUN cadangan. Di satu Wi-Fi biasanya tak terpakai; tidak ada
    // TURN karena semua stasiun berada di jaringan yang sama.
    'ice_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
    ],

];
