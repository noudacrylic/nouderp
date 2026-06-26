<?php

/**
 * Klasifikasi akun untuk Laporan Laba Rugi bertingkat (format mirip Accurate):
 * Pendapatan → HPP → Laba Kotor → Beban Operasional → Laba Operasional →
 * Pendapatan/Beban Non-Operasional → Laba Bersih.
 *
 * Edit di sini bila ada akun baru yang perlu dikelompokkan berbeda.
 */
return [
    // Pendapatan OPERASIONAL = akun pendapatan dgn kode diawali prefix ini.
    // Sisanya (mis. 41xx, 7xxx) otomatis jadi Pendapatan Non-Operasional.
    'operating_revenue_prefix' => '40',

    // Beban Pokok Penjualan (HPP) — dipakai menghitung Laba Kotor.
    'cogs_codes' => ['5001', '5005', '5006', '5007'],

    // Override: pendapatan yang dipaksa Non-Operasional (selain aturan prefix).
    'nonoperating_income_codes' => ['4100', '7100'],

    // Beban Non-Operasional (di bawah Laba Operasional).
    'nonoperating_expense_codes' => ['5200', '7200'],
];
