<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Services\IzinRequestService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin: kotak masuk Pengajuan Izin karyawan (dari PWA). Setujui / Tolak / Batalkan.
 * Sub-tab di modul Absensi → Izin & Sanksi.
 */
class PengajuanIzinController extends Controller
{
    public function __construct(private IzinRequestService $service) {}

    public function index(Request $request)
    {
        $status = $request->get('status', 'pending');

        $query = IzinRequest::with('karyawan')
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true),
                fn ($q) => $q->where('status', $status))
            ->orderByRaw("FIELD(status,'pending','approved','rejected')")
            ->orderByDesc('created_at');

        $requests = $query->paginate(30)->withQueryString();
        $pendingCount = IzinRequest::where('status', 'pending')->count();

        return view('erp.sdm.pengajuan-izin.index', compact('requests', 'status', 'pendingCount'));
    }

    public function approve(int $id)
    {
        $req = IzinRequest::findOrFail($id);
        try {
            $this->service->approve($req, auth()->user()->name ?? 'admin');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pengajuan disetujui & diterapkan.');
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['review_notes' => 'nullable|string|max:255']);
        $req = IzinRequest::findOrFail($id);
        try {
            $this->service->reject($req, auth()->user()->name ?? 'admin', $data['review_notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pengajuan ditolak.');
    }

    public function cancel(int $id)
    {
        $req = IzinRequest::findOrFail($id);
        try {
            $this->service->cancelApproval($req);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Approval dibatalkan, efek di-revert.');
    }
}
