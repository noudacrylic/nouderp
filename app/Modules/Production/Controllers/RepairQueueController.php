<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Services\RepairQueueService;
use Illuminate\Http\Request;

/** Produksi › Barang Perbaikan — antrean barang di Gudang Perbaikan & OP perbaikan yang berjalan. */
class RepairQueueController extends Controller
{
    public function index(Request $request, RepairQueueService $svc)
    {
        return view('erp.production.perbaikan.index', $svc->data($request->q));
    }
}
