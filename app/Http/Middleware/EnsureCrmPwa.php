<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk PWA CRM (`/cs/*`) — aplikasi chat WhatsApp di HP.
 *
 * Syarat akses:
 *  - Sudah login (kalau guest → redirect /login, balik ke /cs setelah login).
 *  - Akun aktif (is_active). Kalau dinonaktifkan → logout + pesan.
 *  - Boleh pakai PWA CRM: flag `pwa_crm` ATAU izin menu `crm.inbox`
 *    (lihat User::canUseCrmPwa()).
 *
 * Sepupu dari EnsureKaryawan yang menjaga `/me/*`. Dipisah, bukan digabung,
 * karena keduanya menjawab pertanyaan yang berbeda: `/me` bertanya "apakah kamu
 * karyawan?", `/cs` bertanya "apakah kamu boleh membalas chat?" — dan satu orang
 * bisa keduanya, salah satunya, atau bukan dua-duanya.
 */
class EnsureCrmPwa
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            // simpan tujuan agar setelah login balik ke halaman /cs yang diminta
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

        if (!$user->canUseCrmPwa()) {
            return redirect(user_landing_url());
        }

        return $next($request);
    }
}
