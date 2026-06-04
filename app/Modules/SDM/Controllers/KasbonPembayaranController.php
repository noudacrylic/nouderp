<?php

namespace App\Modules\SDM\Controllers;

use App\Core\Accounting\Account;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\KasbonPembayaran;
use App\Modules\SDM\Services\KasbonPembayaranService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KasbonPembayaranController extends Controller
{
    public function __construct(protected KasbonPembayaranService $service) {}

    public function index(Request $request)
    {
        $q = KasbonPembayaran::with(['kasbon.karyawan', 'cashAccount'])
            ->orderByDesc('tanggal_bayar')->orderByDesc('id');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($x) use ($s) {
                $x->where('code', 'like', "%{$s}%")
                  ->orWhereHas('kasbon.karyawan', fn($y) => $y->where('name', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('source')) {
            $q->where('source', $request->source);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        $rows = $q->paginate(25)->withQueryString();
        return view('erp.sdm.kasbon-pembayaran.index', compact('rows'));
    }

    public function create(Request $request)
    {
        $aktifKasbon = Kasbon::with('karyawan')
            ->where('status', 'posted')
            ->where('sisa_terhutang', '>', 0)
            ->orderBy('id')->get();

        $selectedKasbon = null;
        if ($request->filled('kasbon_id')) {
            $selectedKasbon = Kasbon::with('karyawan')->find($request->kasbon_id);
        }

        $accounts = $this->cashAccounts();
        return view('erp.sdm.kasbon-pembayaran.create', compact('aktifKasbon', 'selectedKasbon', 'accounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        try {
            $kp = $this->service->createDraftManual($data);
            return redirect()->route('sdm.kasbon-pembayaran.show', $kp->id)
                ->with('success', 'Pembayaran kasbon dibuat sebagai draft.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $kp = KasbonPembayaran::with(['kasbon.karyawan', 'cashAccount', 'journal', 'slip'])->findOrFail($id);
        return view('erp.sdm.kasbon-pembayaran.show', compact('kp'));
    }

    public function edit(int $id)
    {
        $kp = KasbonPembayaran::findOrFail($id);
        if (! $kp->canBeEdited()) {
            return redirect()->route('sdm.kasbon-pembayaran.show', $id)
                ->with('error', 'Pembayaran ini tidak bisa diedit.');
        }
        $aktifKasbon = Kasbon::with('karyawan')
            ->where('status', 'posted')->where('sisa_terhutang', '>', 0)
            ->orWhere('id', $kp->kasbon_id)
            ->orderBy('id')->get();
        $accounts = $this->cashAccounts();
        return view('erp.sdm.kasbon-pembayaran.edit', compact('kp', 'aktifKasbon', 'accounts'));
    }

    public function update(Request $request, int $id)
    {
        $kp = KasbonPembayaran::findOrFail($id);
        $data = $this->validateData($request);
        try {
            $this->service->updateManual($kp, $data);
            return redirect()->route('sdm.kasbon-pembayaran.show', $kp->id)
                ->with('success', 'Pembayaran kasbon diperbarui.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function post(int $id)
    {
        $kp = KasbonPembayaran::findOrFail($id);
        try {
            $this->service->post($kp);
            return back()->with('success', 'Pembayaran ' . $kp->code . ' diposting.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(int $id)
    {
        $kp = KasbonPembayaran::findOrFail($id);
        try {
            $this->service->void($kp);
            return redirect(list_url('sdm.kasbon-pembayaran.index'))
                ->with('success', 'Pembayaran ' . $kp->code . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        $kp = KasbonPembayaran::findOrFail($id);
        if (! $kp->isDraft() || $kp->source !== 'manual') {
            return back()->with('error', 'Hanya draft manual yang bisa dihapus.');
        }
        $kp->delete();
        return redirect(list_url('sdm.kasbon-pembayaran.index'))->with('success', 'Draft dihapus.');
    }

    protected function validateData(Request $request): array
    {
        $request->validate([
            'kasbon_id'       => 'required|exists:sdm_kasbon,id',
            'tanggal_bayar'   => 'required|date',
            'jumlah'          => 'required|string',
            'cash_account_id' => 'required|exists:accounts,id',
            'notes'           => 'nullable|string',
        ]);
        return [
            'kasbon_id'       => (int) $request->kasbon_id,
            'tanggal_bayar'   => $request->tanggal_bayar,
            'jumlah'          => (float) clean_number($request->jumlah),
            'cash_account_id' => (int) $request->cash_account_id,
            'notes'           => $request->notes,
        ];
    }

    protected function cashAccounts()
    {
        return Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->orderBy('code')->get();
    }
}
