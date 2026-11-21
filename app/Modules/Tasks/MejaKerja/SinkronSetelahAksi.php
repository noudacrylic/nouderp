<?php

namespace App\Modules\Tasks\MejaKerja;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tombol apa pun di Task Manager (kirim tugas, jawab kartu, tunda, lampirkan foto, …) langsung
 * diikuti sinkron Meja Kerja — supaya kartu baru/selesai tampil saat halaman kembali, tanpa
 * menunggu jadwal 2 menitan atau menekan "Sinkron sekarang" (permintaan owner 21 Nov 2026:
 * tugas dikirim tapi kartunya belum muncul → membingungkan). Sinkron penuh ±1,5 detik di server.
 *
 * Hanya permintaan tulis yang berhasil; sinkron sedang berjalan (kunci terpegang) dilewati saja.
 * Galat sinkron tidak menggagalkan aksi yang sudah tersimpan.
 */
class SinkronSetelahAksi
{
    public function __construct(private MejaKerjaService $meja) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')
            && !$request->routeIs('tasks.meja.sinkron')
            && $response->getStatusCode() < 400
            && !session()->has('errors')) {
            try {
                $this->meja->sinkronTerkunci();
            } catch (\Throwable $e) {
                Log::warning('MejaKerja: sinkron setelah aksi gagal', ['route' => $request->route()?->getName(), 'error' => $e->getMessage()]);
            }
        }

        return $response;
    }
}
