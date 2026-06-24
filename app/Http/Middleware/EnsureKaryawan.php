<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk PWA Karyawan (`/me/*`).
 *
 * Syarat akses:
 *  - Sudah login (kalau guest → redirect /login, balik ke /me setelah login).
 *  - Akun aktif (is_active). Kalau dinonaktifkan → logout + pesan.
 *  - Role 'karyawan' DAN punya karyawan_id (terhubung ke data karyawan).
 *
 * Role 'user'/admin/super_admin tidak diarahkan ke sini (mereka punya akses ERP).
 * Halaman register (`/me/register`) TIDAK pakai middleware ini (publik).
 */
class EnsureKaryawan
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            // simpan tujuan agar setelah login balik ke halaman /me yang diminta
            return redirect()->guest(route('login'));
        }

        $user = auth()->user();

        if (!$user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['username' => 'Akun Anda belum aktif / telah dinonaktifkan. Hubungi admin.']);
        }

        // Bukan role karyawan → kembalikan ke landing sesuai perannya.
        if ($user->role !== 'karyawan' || !$user->karyawan_id) {
            return redirect(user_landing_url());
        }

        return $next($request);
    }
}
