<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Setiap kunjungan GET ke halaman index (route name diakhiri `.index` atau
 * submenu list seperti `.umum` / `.ongkir` / `.refund` / `.freight`), simpan
 * fullUrl ke session. Controller yang redirect kembali ke list bisa baca
 * session ini supaya filter query string user tidak hilang setelah edit.
 */
class RememberListUrl
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && $request->route() && $request->route()->getName()) {
            $name = $request->route()->getName();
            if (preg_match('/\.(index|umum|ongkir|refund|freight|general|special)$/', $name)) {
                session()->put("list_url:$name", $request->fullUrl());
            }
        }
        return $next($request);
    }
}
