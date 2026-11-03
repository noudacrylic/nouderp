<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Services\IzinKeputusanService;
use App\Modules\SDM\Services\IzinReviewService;
use App\Modules\Tasks\TugasOwner\TugasOwnerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Owner: kotak masuk Pengajuan Izin karyawan (dari PWA). Setujui / Tolak / Batalkan.
 * Sub-tab di modul Absensi → Izin & Sanksi. Hanya owner — admin tidak memeriksa izin.
 */
class PengajuanIzinController extends Controller
{
    public function __construct(private IzinKeputusanService $keputusan)
    {
        $this->middleware(function ($request, $next) {
            abort_unless(app(TugasOwnerService::class)->owner($request->user()), 403, 'Pengajuan izin hanya untuk owner.');
            return $next($request);
        });
    }

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

        return view('erp.sdm.pengajuan-izin.index', [
            'requests'     => $requests,
            'status'       => $status,
            'pendingCount' => $pendingCount,
            'adaScan'      => $this->tanggalYangAdaScan($requests->getCollection()),
        ]);
    }

    /**
     * Pengajuan "tidak masuk" yang tanggalnya justru ADA scan — tanda paling sering dari
     * salah pilih tanggal. Dihitung sekali untuk seluruh halaman (bukan per baris) supaya
     * daftar tidak menembak database puluhan kali hanya untuk sebuah lencana.
     *
     * @return array<int,bool>  [izin_request_id => true]
     */
    private function tanggalYangAdaScan($rows): array
    {
        $absen = ['cuti', 'sakit', 'izin_pagi', 'izin_sore'];

        $kandidat = $rows->filter(fn ($r) => $r->isPending() && in_array($r->type, $absen, true));
        if ($kandidat->isEmpty()) {
            return [];
        }

        $scans = \App\Modules\SDM\Models\FingerprintLog::whereIn('karyawan_id', $kandidat->pluck('karyawan_id')->unique())
            ->whereBetween('scan_at', [
                $kandidat->min('tanggal')->copy()->startOfDay(),
                $kandidat->max(fn ($r) => $r->tanggal_akhir ?? $r->tanggal)->copy()->endOfDay(),
            ])
            ->get(['karyawan_id', 'scan_at'])
            ->map(fn ($s) => $s->karyawan_id . '|' . \Carbon\Carbon::parse($s->scan_at)->toDateString())
            ->flip();

        $out = [];
        foreach ($kandidat as $r) {
            $akhir = $r->tanggal_akhir ?? $r->tanggal;
            for ($d = $r->tanggal->copy(); $d->lte($akhir); $d->addDay()) {
                if (isset($scans[$r->karyawan_id . '|' . $d->toDateString()])) {
                    $out[$r->id] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Halaman peninjauan satu pengajuan.
     *
     * Ada karena daftar saja tidak cukup untuk memutuskan: dari nama + tipe + alasan, tidak
     * kelihatan apakah orangnya sebenarnya masuk hari itu atau salah pilih tanggal. Di sini
     * fakta hari itu ditampilkan apa adanya, plus peringatan untuk pola yang biasanya keliru.
     */
    public function show(int $id, IzinReviewService $review)
    {
        $req = IzinRequest::with('karyawan.schedules')->findOrFail($id);

        return view('erp.sdm.pengajuan-izin.show', [
            'req'    => $req,
            'review' => $review->build($req),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $req = IzinRequest::findOrFail($id);
        try {
            $pesan = $this->keputusan->putuskan($req, true, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->kembaliKeAntrean($req)->with('success', $pesan);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['review_notes' => 'nullable|string|max:255']);
        $req = IzinRequest::findOrFail($id);
        try {
            $pesan = $this->keputusan->putuskan($req, false, $request->user(), $data['review_notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->kembaliKeAntrean($req)->with('success', $pesan);
    }

    /**
     * Setelah diputuskan, kembali ke ANTREAN — bukan ke halaman tinjauannya. Keputusannya
     * diambil di halaman detail, dan yang dibutuhkan berikutnya adalah pengajuan berikutnya.
     */
    private function kembaliKeAntrean(IzinRequest $req)
    {
        return redirect()->route('sdm.pengajuan-izin.index', ['status' => 'pending', 'highlight' => $req->id]);
    }

    public function cancel(int $id)
    {
        $req = IzinRequest::findOrFail($id);
        try {
            $this->keputusan->batalkan($req);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return $this->kembaliKeAntrean($req)->with('success', 'Approval dibatalkan, efek di-revert.');
    }
}
