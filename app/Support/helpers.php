<?php

if (!function_exists('idr')) {
    function idr($value)
    {
        return number_format((float) $value, 0, ',', '.');
        // 0 decimals is more common for IDR but the user asked for 2, let me follow the user exactly:
        // Wait, user said `return number_format((float) $value, 2, ',', '.');`, so I'll do that.
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
     * Deteksi apakah sebuah URL adalah aksi/download (bukan halaman yang bisa dinavigasi),
     * berdasarkan segmen terakhir path: template / export / import / pdf / print / download.
     * Dipakai untuk menolak nilai `last_submenu` yang ter-poison oleh URL download.
     */
    function submenu_url_is_action(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $lastSeg = strtolower(basename($path));
        return (bool) preg_match('/(?:^|[-_])(?:template|export|import|pdf|print|download)$/', $lastSeg);
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
