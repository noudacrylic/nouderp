<?php

namespace App\Http\Controllers\Settings;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog;
use Illuminate\Http\Request;

class JubelioSyncLogController extends Controller
{
    /** Riwayat per-kejadian sinkron Jubelio (Pesanan/Stok/Harga) + filter. */
    public function index(Request $request)
    {
        $type   = $request->query('type');     // order|stock|price
        $status = $request->query('status');   // success|failed|skipped
        $q      = trim((string) $request->query('q', ''));

        $logs = JubelioSyncLog::query()
            ->when(in_array($type, ['order', 'stock', 'price'], true), fn ($w) => $w->where('type', $type))
            ->when(in_array($status, ['success', 'failed', 'skipped'], true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', function ($w) use ($q) {
                $w->where(function ($s) use ($q) {
                    $s->where('title', 'like', "%{$q}%")
                      ->orWhere('reference', 'like', "%{$q}%")
                      ->orWhere('message', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // Ringkasan status sinkron saat ini (dari flag produk yang sudah ada).
        $summary = [
            'stock_pending' => Product::where('sync_to_jubelio', true)->where('jubelio_sync_pending', true)->count(),
            'price_pending' => Product::where('sync_to_jubelio', true)->where('jubelio_price_pending', true)->count(),
            'synced_total'  => Product::where('sync_to_jubelio', true)->count(),
            'failed_24h'    => JubelioSyncLog::where('status', JubelioSyncLog::FAIL)
                                   ->where('created_at', '>=', now()->subDay())->count(),
        ];

        return view('erp.settings.jubelio.history', compact('logs', 'summary', 'type', 'status', 'q'));
    }
}
