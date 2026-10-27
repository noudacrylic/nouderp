<?php

/*
 * QC Timbang — timbangan CAS SW-II LED (3 kg / 0,1 g) lewat RS232 → USB → Web Serial.
 *
 * Diverifikasi dengan unit asli 22 Okt 2026 (ATEN/Prolific USB-serial, COM3, 9600 8N1):
 * alat mengirim baris ASCII terus-menerus ~10×/detik, tanpa flag stabil & tanpa BCC —
 * BUKAN bingkai SOH…EOT seperti di manual CAS Australia.
 *
 *   "  0.8555kg
"  →  20 20 30 2e 38 35 35 35 6b 67 0d 0a
 *
 * Format bingkai manual masih tersedia ('format' => 'frame') bila alat lain memakainya.
 */
return [

    // Toleransi bawaan bila profil & layar Setting kosong.
    'default_tolerance' => ['mode' => 'persen', 'value' => 3],

    // Analisa komponen: maksimal keping hilang/lebih yang dicoba dikombinasikan.
    'analisa_maks_keping' => 3,

    'serial' => [
        'baud_rate'  => 9600,
        'data_bits'  => 8,
        'parity'     => 'none',
        'stop_bits'  => 1,

        // Kirim DC1 (0x11) berkala bila timbangan di mode "Communicate with PC"
        // (tidak kirim sendiri). 0 = mati (timbangan kirim otomatis saat stabil).
        'poll_ms'    => 0,
    ],

    'parser' => [
        // 'line' = baris ASCII diakhiri LF (unit kita); 'frame' = bingkai SOH…EOT manual CAS.
        'format'       => 'line',
        'start_byte'   => 0x01,      // SOH (frame saja)
        'end_byte'     => 0x0A,      // LF untuk 'line'; ganti 0x04 (EOT) untuk 'frame'
        // ── Sisanya hanya untuk 'frame'. Posisi field dihitung dari SOH (indeks 0).
        'status_index' => 2,         // STA
        'sign_index'   => 3,         // SIGN
        'weight_start' => 4,         // W5
        'weight_length'=> 6,         // W5..W0
        'unit_start'   => 10,        // UN1 UN2
        'bcc_index'    => 12,
        // 'auto' = pakai titik bila ada; angka = jumlah digit desimal implisit (dibagi 10^n).
        'decimals'     => 'auto',
        // Cakupan XOR BCC: 'off' | 'stx_to_unit' | 'sta_to_unit'. Belum pasti → off dulu.
        'bcc'          => 'off',
    ],

    // Tangkap otomatis: berat stabil di atas ambang selama sekian ms dengan simpangan kecil,
    // lalu timbangan harus kembali mendekati nol sebelum tangkapan berikutnya.
    'capture' => [
        'min_gram'      => 2,
        'stable_ms'     => 800,
        'jitter_gram'   => 0.2,
        'zero_gram'     => 1,
    ],
];
