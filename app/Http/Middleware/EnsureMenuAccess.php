<?php

namespace App\Http\Middleware;

use App\Services\MenuRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: protect semua route `/erp/*` dengan auth + menu permission check.
 *
 * Strategi:
 *  - Skip kalau request path bukan `erp/*` (login, iclock, customers ajax, dll lewat tanpa cek)
 *  - Skip kalau route name null (closure routes)
 *  - Force auth: kalau guest → redirect /login
 *  - Resolve route name → menu_key via registry
 *  - Kalau menu_key null:
 *      - role user → deny (default policy)
 *      - role admin/super_admin → allow
 *  - Kalau menu_key found → cek user_can_access
 */
class EnsureMenuAccess
{
    public function __construct(private MenuRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('erp', 'erp/*')) {
            return $next($request);
        }

        if ($request->is('erp/health')) {
            return $next($request);
        }

        if (!auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->guest(route('login'));
        }

        $user = auth()->user();
        if (!$user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['username' => 'Akun Anda telah dinonaktifkan.']);
        }

        // super_admin & admin → akses penuh
        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return $next($request);
        }

        $route = $request->route();
        $name = $route?->getName();

        // Route tanpa nama (closure/helper AJAX). Catatan: saat `route:cache` aktif
        // (produksi, via `php artisan optimize`), Laravel memberi nama auto `generated::xxx`
        // ke route tanpa nama → `getName()` jadi non-null. Perlakukan itu sama dgn tanpa nama,
        // jika tidak semua endpoint helper (mis. /erp/api/products/search) jadi 403 utk non-admin.
        if (!$name || str_starts_with($name, 'generated::')) {
            return $next($request);
        }

        $menuKey = $this->registry->resolveMenuKey($name);

        if ($menuKey === null) {
            // Route bantu (search, inline-create) bernama `{modul}.api.*` / `{modul}.ajax.*`.
            // Tidak punya menu sendiri tapi dipakai DI DALAM halaman menu. Izinkan kalau user
            // punya akses ke minimal satu menu di modul yang sama (mis. bisa pakai "Tambah
            // Pemasok" / pencarian saat punya akses Purchase Order).
            if ($this->isAuxiliaryRoute($name) && $this->canAccessModuleOf($name)) {
                return $next($request);
            }
            return $this->deny($request, "Akses ditolak (route '{$name}' tidak terdaftar di menu).");
        }

        // Special case: production.process butuh check per ?department_id
        if ($menuKey === 'production.process') {
            $deptId = $request->query('department_id');
            $deptId = is_numeric($deptId) ? (int) $deptId : null;
            $ok = $this->registry->userCanAccessProcessRoute(
                $deptId,
                fn($key) => $user->hasMenuPermission($key)
            );
            if (!$ok) {
                $msg = $deptId
                    ? "Anda tidak punya akses ke divisi Proses Produksi ini."
                    : "Anda tidak punya akses ke Proses Produksi.";
                return $this->deny($request, $msg);
            }
            return $next($request);
        }

        if (!user_can_access($menuKey)) {
            return $this->deny($request, "Anda tidak punya akses ke menu '{$menuKey}'.");
        }

        return $next($request);
    }

    /** Route bantu AJAX/API: punya segmen `api` atau `ajax` di namanya. */
    private function isAuxiliaryRoute(string $name): bool
    {
        $segments = explode('.', $name);
        return in_array('api', $segments, true) || in_array('ajax', $segments, true);
    }

    /** User punya akses ke minimal satu menu di modul (segmen pertama) route ini? */
    private function canAccessModuleOf(string $name): bool
    {
        $module = explode('.', $name, 2)[0];
        foreach ($this->registry->moduleKeys($module) as $key) {
            if (user_can_access($key)) {
                return true;
            }
        }
        return false;
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }
        abort(403, $message);
    }
}
