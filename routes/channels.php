<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Saluran Broadcast
|--------------------------------------------------------------------------
|
| Intercom divisi: satu saluran privat bersama untuk semua stasiun. Isinya
| hanya SINYAL panggilan (memanggil, SDP, ICE, tutup) yang dikirim antar
| peramban sebagai client event — suaranya sendiri mengalir langsung antar
| perangkat lewat WebRTC di Wi-Fi kantor, tidak pernah lewat server.
|
| Siapa saja yang login ERP dan akunnya aktif boleh masuk: stasiun terikat
| ke PERANGKAT (dipilih di perangkat itu), bukan ke akun atau menu.
|
*/

Broadcast::channel('intercom', fn ($user) => (bool) $user->is_active);
