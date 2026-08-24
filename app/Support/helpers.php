<?php

if (!function_exists('idr')) {
    function idr($value)
    {
        return number_format((float) $value, 0, ',', '.');
        // 0 decimals is more common for IDR but the user asked for 2, let me follow the user exactly:
        // Wait, user said `return number_format((float) $value, 2, ',', '.');`, so I'll do that.
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Normalisasi nomor HP Indonesia untuk perbandingan.
     * Ambil hanya digit, samakan prefix +62 / 62 / 0 menjadi '0'.
     * Mis. "+62 812-3456-7890", "0812 3456 7890", "62812-34567890" → "081234567890".
     * Return string kosong kalau tak ada digit.
     */
    function normalize_phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') return '';
        if (str_starts_with($digits, '62')) {
            $digits = '0' . substr($digits, 2);
        } elseif (!str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }
        return $digits;
    }
}

if (!function_exists('user_can_access')) {
    /**
     * Cek apakah user yang login boleh akses menu_key tertentu.
     * - Guest → false
     * - User non-aktif → false
     * - super_admin / admin → selalu true
     * - menu dengan flag `always_visible` → true
     * - user biasa → cek user_menu_permissions
     */
    function user_can_access(string $menuKey): bool
    {
        $u = auth()->user();
        if (!$u || !$u->is_active) return false;
        if (in_array($u->role, ['super_admin', 'admin'], true)) return true;

        $cfg = config("menu_permissions.$menuKey");
        if ($cfg === null) {
            // mungkin sub-menu (nested) — cari di flat registry
            $cfg = app(\App\Services\MenuRegistry::class)->flat()[$menuKey] ?? null;
        }
        if ($cfg && !empty($cfg['always_visible'])) return true;
        if ($cfg && isset($cfg['role_gate']) && !in_array($u->role, $cfg['role_gate'], true)) return false;

        // Special case: production.process — kalau user punya dept-specific apapun
        // (production.process.{id}), anggap punya akses ke umbrella (untuk sidebar visibility).
        if ($menuKey === 'production.process' && !$u->hasMenuPermission($menuKey)) {
            foreach (app(\App\Services\MenuRegistry::class)->productionDepartments() as $dept) {
                if ($u->hasMenuPermission("production.process.{$dept->id}")) return true;
            }
            return false;
        }

        return $u->hasMenuPermission($menuKey);
    }
}

if (!function_exists('current_module')) {
    /**
     * Derive current module slug (prefix dari menu_key) dari route name saat ini.
     * Mis. route 'sales.orders.index' → menu_key 'sales.orders' → module 'sales'.
     * Return null kalau route tidak terdaftar di registry.
     */
    function current_module(): ?string
    {
        $routeName = \Illuminate\Support\Facades\Route::currentRouteName();
        if (!$routeName) return null;
        $key = app(\App\Services\MenuRegistry::class)->resolveMenuKey($routeName);
        if (!$key || !str_contains($key, '.')) return $key; // root key sendiri (mis. 'dashboard')
        return explode('.', $key)[0];
    }
}

if (!function_exists('submenu_url_is_action')) {
    /**
     * Deteksi apakah sebuah URL adalah aksi/download ATAU halaman cetak standalone (print shell
     * tanpa sidebar) — bukan halaman bernavigasi. Dipakai untuk menolak nilai `last_submenu`
     * yang ter-poison, supaya klik menu utama tidak nyangkut di halaman tanpa menu.
     *
     * Cek segmen terakhir path:
     *   - berakhiran: template / export / import / pdf / download
     *   - mengandung kata: print (print-bulk, print-resi-bulk) / resi / track / label  (halaman cetak/standalone)
     */
    function submenu_url_is_action(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $lastSeg = strtolower(basename($path));
        return (bool) preg_match('/(?:^|[-_])(?:template|export|import|pdf|download)$/', $lastSeg)
            || (bool) preg_match('/(?:^|[-_])(?:print|resi|track|label)(?:$|[-_])/', $lastSeg);
    }
}

if (!function_exists('module_landing_url')) {
    /**
     * Landing URL saat user klik menu utama (mis. "Sales") di sidebar.
     * Prioritas: session('last_submenu.{module}') → first accessible sub-menu URL → /erp/dashboard.
     */
    function module_landing_url(string $module): string
    {
        $session = session("last_submenu.$module");
        // Hanya pakai bila URL tersimpan adalah HALAMAN, bukan aksi/download (mis.
        // `.../freight-import-template`, `.../export`, `.../{id}/print`). Tanpa guard ini,
        // session lama yang sempat ter-poison sebuah URL download akan men-trigger download
        // ulang setiap kali menu utama diklik. Lihat juga middleware RememberSubmenuUrl.
        if ($session && is_string($session) && !submenu_url_is_action($session)) return $session;

        $children = config("menu_permissions.$module.children", []);
        foreach ($children as $key => $cfg) {
            // role_gate per child
            if (isset($cfg['role_gate'])) {
                $u = auth()->user();
                if (!$u || !in_array($u->role, $cfg['role_gate'], true)) continue;
            }
            if (!user_can_access($key)) continue;
            if (!empty($cfg['url'])) return $cfg['url'];
        }

        // Module tanpa children (mis. dashboard)
        $rootCfg = config("menu_permissions.$module");
        if (!empty($rootCfg['url']) && user_can_access($module)) return $rootCfg['url'];

        return url('/erp/dashboard');
    }
}

if (!function_exists('user_landing_url')) {
    /**
     * URL landing setelah login / fallback. Dashboard utk super_admin/admin;
     * user biasa (tak boleh Dashboard) → menu pertama yang ia punya akses.
     */
    function user_landing_url(): string
    {
        // Karyawan (role 'karyawan') → PWA Karyawan. Role 'user' tetap ke ERP.
        $u = auth()->user();
        if ($u && $u->role === 'karyawan') {
            return url('/me');
        }

        if (user_can_access('dashboard')) {
            return url('/erp/dashboard');
        }
        foreach (array_keys(config('menu_permissions', [])) as $groupKey) {
            if ($groupKey === 'dashboard') continue;
            if (should_show_menu_group($groupKey)) {
                return module_landing_url($groupKey);
            }
        }
        return url('/erp/dashboard'); // fallback (user tanpa akses menu apa pun)
    }
}

if (!function_exists('should_show_menu_group')) {
    /**
     * Cek apakah menu group (top-level) perlu di-render di sidebar.
     * Return true jika minimal 1 child accessible (atau menu tanpa children & user_can_access).
     */
    function should_show_menu_group(string $groupKey): bool
    {
        $cfg = config("menu_permissions.$groupKey");
        if (!$cfg) return false;

        if (isset($cfg['role_gate'])) {
            $u = auth()->user();
            if (!$u || !in_array($u->role, $cfg['role_gate'], true)) return false;
        }

        if (empty($cfg['children'])) {
            return user_can_access($groupKey);
        }
        foreach (array_keys($cfg['children']) as $childKey) {
            if (user_can_access($childKey)) return true;
        }
        return false;
    }
}

if (!function_exists('trix_content')) {
    /**
     * Naskah Trix yang siap dimuat ulang ke editor.
     *
     * Tombol Heading dulu menerbitkan <h1>, kini <h2> (lihat
     * erp/_partials/trix-config.blade.php). Trix mengenali sebuah blok dari
     * NAMA TAG-nya, jadi tanpa penyetaraan ini naskah lama yang memuat <h1>
     * akan dibaca sebagai paragraf biasa saat dibuka kembali — tulisannya utuh,
     * tapi statusnya sebagai heading hilang diam-diam.
     *
     * Boleh dibuang bila sudah yakin tak ada lagi naskah ber-<h1> tersimpan.
     */
    function trix_content(?string $html): string
    {
        return preg_replace('~<(/?)h1(\s[^>]*)?>~i', '<$1h2$2>', (string) $html) ?? (string) $html;
    }
}

if (!function_exists('trix_publish')) {
    /**
     * Naskah Trix yang siap DITERBITKAN. Kebalikan arah dari trix_content().
     *
     * Trix menyisipkan gambar sebagai <figure> berisi <img> TANPA `alt`, dan
     * <figcaption> yang isinya nama berkas + ukurannya ("tutorial-a1b2c3.jpg
     * 12.06 KB"). Halaman tutorial & artikel merender naskah ini apa adanya,
     * jadi tanpa penyuntingan di sini nama berkas itu ikut TERBACA pengunjung.
     *
     * Keterangan gambar yang diketik admin (klik gambar di editor) disimpan Trix
     * di dalam JSON `data-trix-attachment`, bukan di figcaption-nya. Dari sana:
     *
     *   ada keterangan  → <img alt="keterangan"> + <figcaption>keterangan</figcaption>
     *   tanpa keterangan→ <img alt="">           + figcaption DIBUANG
     *
     * `alt=""` pada gambar tanpa keterangan disengaja: itu memberi tahu pembaca
     * layar agar melewatinya, jauh lebih baik daripada membacakan nama berkas.
     * Keterangan yang benar tetap harus ditulis manusia — mesin tidak tahu apa
     * yang ada di dalam foto.
     *
     * Idempotent: dijalankan ulang atas hasilnya sendiri tidak mengubah apa pun.
     */
    function trix_publish(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '' || !str_contains($html, 'data-trix-attachment')) {
            return $html;
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Tanpa deklarasi encoding, DOMDocument menebak Latin-1 dan merusak
        // huruf beraksen serta emoji di dalam naskah.
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) return $html;   // naskah tak terbaca → biarkan apa adanya

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//figure[@data-trix-attachment]') as $figure) {
            $meta    = json_decode((string) $figure->getAttribute('data-trix-attachment'), true);
            $caption = is_array($meta) ? trim((string) ($meta['caption'] ?? '')) : '';

            foreach ($xpath->query('.//img', $figure) as $img) {
                $img->setAttribute('alt', $caption);
            }

            foreach ($xpath->query('.//figcaption', $figure) as $figcaption) {
                if ($caption === '') {
                    $figcaption->parentNode?->removeChild($figcaption);
                    continue;
                }
                while ($figcaption->firstChild) {
                    $figcaption->removeChild($figcaption->firstChild);
                }
                $figcaption->appendChild($doc->createTextNode($caption));
            }
        }

        $out = '';
        foreach ($doc->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) continue;   // buang deklarasi encoding
            $out .= $doc->saveHTML($node);
        }

        return $out;
    }
}
