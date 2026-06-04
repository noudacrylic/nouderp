<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust ngrok / reverse proxy supaya Laravel tahu request asli HTTPS
        // (mencegah mixed-content saat development via ngrok untuk Midtrans).
        $middleware->trustProxies(at: '*', headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );

        // ADMS endpoint untuk fingerprint machine — device tidak kirim CSRF token.
        // Midtrans webhook & public pay endpoint — server-to-server, tidak kenal CSRF.
        $middleware->validateCsrfTokens(except: [
            'iclock/*',
            'midtrans/notify',
            'pay/*',
            'jubelio/webhook/*',
        ]);

        // Auto-record URL halaman index (dengan filter query string) ke session,
        // supaya redirect setelah save/finish bisa kembali ke list yang sudah difilter.
        $middleware->web(append: [
            \App\Http\Middleware\RememberListUrl::class,
            \App\Http\Middleware\EnsureMenuAccess::class,
            \App\Http\Middleware\RememberSubmenuUrl::class,
        ]);

        $middleware->alias([
            'midtrans.signature' => \App\Modules\Payment\Http\Middleware\VerifyMidtransSignature::class,
            'jubelio.signature'  => \App\Modules\Marketplace\Jubelio\Http\Middleware\VerifyJubelioSignature::class,
        ]);

        $middleware->redirectGuestsTo(fn() => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sesi/CSRF kedaluwarsa (419): jangan tampilkan halaman buntu "Page Expired".
        // Kembalikan user ke form dengan input terisi + pesan jelas agar tinggal Simpan lagi.
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.'], 419);
            }
            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation']))
                ->withErrors(['session' => 'Sesi Anda kedaluwarsa (halaman terbuka terlalu lama). Silakan periksa data lalu klik Simpan lagi.']);
        });
    })->create();
