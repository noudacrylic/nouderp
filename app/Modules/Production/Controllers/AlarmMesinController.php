<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Services\PantauMesinService;
use Illuminate\Http\JsonResponse;

/**
 * Alarm "mesin selesai" untuk perangkat CNC — ditanya berkala oleh public/js/cnc-alarm.js.
 * Di bawah /erp/intercom supaya terbuka untuk perangkat stasiun apa pun hak menu akunnya.
 */
class AlarmMesinController extends Controller
{
    public function __invoke(PantauMesinService $pantau): JsonResponse
    {
        return response()->json(['menunggu' => $pantau->menungguDiambil(), 'jam' => now()->format('H:i:s')]);
    }
}
