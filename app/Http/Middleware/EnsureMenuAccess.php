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

        if (!$name) {
            return $next($request);
        }

        $menuKey = $this->registry->resolveMenuKey($name);

        if ($menuKey === null) {
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

    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }
        abort(403, $message);
    }
}
