/*
 * CSS PWA NOUD Chat (/cs) — pengganti cdn.tailwindcss.com.
 *
 * CDN Play menyusun CSS DI HP tiap halaman dibuka, dan menyusun ulang tiap
 * daftar/chat disegarkan (polling 8 detik) — berat di HP kelas menengah.
 * Versinya SENGAJA v3.4 (paket alias `tailwindcss-v3`), sama dengan CDN,
 * bukan v4 milik Vite: border/shadow/rounded/ring bawaan v4 berbeda dan
 * tampilan /cs akan bergeser.
 *
 * Sumbernya SELURUH view + app/: /cs menyertakan partial Inbox ERP, flash,
 * dan kelas yang dirakit di PHP. Hasil: public/css/cs.css (di-commit, karena
 * deploy rutin cuma git pull). Ubah view yang tampil di /cs → `npm run build:cs`.
 */
module.exports = {
    content: [
        './resources/views/**/*.blade.php',
        './resources/pwa/**/*.js',
        './app/**/*.php',
        './config/**/*.php',
    ],
    theme: { extend: {} },
    plugins: [],
};
