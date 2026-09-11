<?php

namespace App\Modules\CRM\Support;

/**
 * Terjemahkan tautan Inbox desktop jadi tautan PWA `/cs`.
 *
 * Notifikasi chat dibuat SEKALI untuk semua perangkat seorang CS — lonceng ERP
 * di laptop dan PWA di HP membaca baris yang sama, jadi url-nya tidak bisa
 * dipilih saat dibuat. Yang menentukan bukan siapa penerimanya, melainkan
 * DI MANA ia sedang menekan: ditekan dari HP harus membuka layar chat PWA,
 * bukan melemparkan CS ke Inbox desktop yang tiga kolomnya tidak muat di layar
 * HP — dan untuk akun CS chat-saja, Inbox desktop malah berujung halaman ditolak.
 *
 * ⚠️ KEMBAR dengan `petaKeCs()` di resources/pwa/cs-sw.js. Service worker tidak
 * bisa memanggil PHP, jadi aturan yang sama hidup di dua tempat — ubah
 * berpasangan, kalau tidak notifikasi dari rak HP dan notifikasi dari dalam
 * aplikasi akan mendarat di dua tempat berbeda.
 */
class UrlPwa
{
    /** Tautan apa pun yang bukan layar CRM dibiarkan apa adanya. */
    public static function dariErp(?string $url): ?string
    {
        if (blank($url)) {
            return $url;
        }

        // Yang disimpan route() adalah URL absolut; yang dicocokkan hanya
        // path-nya supaya tetap benar di host mana pun (lokal, ngrok, produksi).
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = '/' . trim($path, '/');

        if (preg_match('#^/erp/crm/(\d+)$#', $path, $cocok)) {
            return '/cs/' . $cocok[1];
        }

        if ($path === '/erp/crm') {
            return '/cs';
        }

        return $url;
    }
}
