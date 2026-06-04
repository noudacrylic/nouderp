<?php

namespace App\Http\Middleware;

use App\Services\MenuRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record URL terakhir yang dikunjungi per-module ke session, sehingga klik menu utama
 * di sidebar redirect ke sub-menu yang terakhir dilihat (bukan default landing).
 *
 * Session key: `last_submenu.{module}` (mis. `last_submenu.sales`).
 * Dipakai oleh helper `module_landing_url($module)`.
 */
class RememberSubmenuUrl
{
    public function __construct(private MenuRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return $response;
        }

        // Hanya untuk response 2xx
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) return $response;

        // Hanya ingat HALAMAN yang bisa dinavigasi (HTML), BUKAN aksi download/export.
        // Tanpa guard ini, GET seperti `freight-import-template` (streamDownload xlsx) ikut
        // tersimpan sebagai last_submenu, sehingga klik menu utama malah men-trigger download lagi.
        $disposition = (string) $response->headers->get('Content-Disposition');
        if (stripos($disposition, 'attachment') !== false) return $response;

        $contentType = (string) $response->headers->get('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) return $response;

        $name = $request->route()?->getName();
        if (!$name) return $response;

        $key = $this->registry->resolveMenuKey($name);
        if (!$key || !str_contains($key, '.')) return $response;

        $module = explode('.', $key)[0];
        session(["last_submenu.{$module}" => $request->fullUrl()]);

        return $response;
    }
}
