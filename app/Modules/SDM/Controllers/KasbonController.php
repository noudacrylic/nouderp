<?php

namespace App\Modules\SDM\Controllers;

use App\Core\Accounting\Account;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Services\KasbonService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KasbonController extends Controller
{
    public function __construct(protected KasbonService $service) {}

    public function index(Request $request)
    {
        $q = Kasbon::with(['karyawan', 'cashAccount'])->orderByDesc('id');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($x) use ($s) {
                $x->where('code', 'like', "%{$s}%")
                  ->orWhereHas('karyawan', fn($y) => $y->where('name', 'like', "%{$s}%")
                                                       ->orWhere('staf_code', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('karyawan_id')) {
            $q->where('karyawan_id', $request->karyawan_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        $rows = $q->paginate(25)->withQueryString();
        $karyawans = Karyawan::active()->orderBy('name')->get();

        return view('erp.sdm.kasbon.index', compact('rows', 'karyawans'));
    }

    public function create()
    {
        $karyawans = Karyawan::active()->orderBy('name')->get();
        $accounts  = $this->cashAccounts();
        return view('erp.sdm.kasbon.create', compact('karyawans', 'accounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        try {
            $kasbon = $this->service->createDraft($data);
            return redirect()->route('sdm.kasbon.show', $kasbon->id)
                ->with('success', 'Kasbon dibuat sebagai draft.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $kasbon = Kasbon::with(['karyawan', 'cashAccount', 'journal', 'pembayaran' => fn($q) => $q->orderByDesc('id')])
            ->findOrFail($id);
        return view('erp.sdm.kasbon.show', compact('kasbon'));
    }

    public function edit(int $id)
    {
        $kasbon = Kasbon::findOrFail($id);
        if (! $kasbon->canBeEdited()) {
            return redirect()->route('sdm.kasbon.show', $id)
                ->with('error', 'Kasbon yang sudah diposting tidak bisa diedit.');
        }
        $karyawans = Karyawan::active()->orderBy('name')->get();
        $accounts  = $this->cashAccounts();
        return view('erp.sdm.kasbon.edit', compact('kasbon', 'karyawans', 'accounts'));
    }

    public function update(Request $request, int $id)
    {
        $kasbon = Kasbon::findOrFail($id);
        $data = $this->validateData($request);
        try {
            $this->service->update($kasbon, $data);
            return redirect()->route('sdm.kasbon.show', $kasbon->id)
                ->with('success', 'Kasbon diperbarui.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function post(int $id)
    {
        $kasbon = Kasbon::findOrFail($id);
        try {
            $this->service->post($kasbon);
            return back()->with('success', 'Kasbon ' . $kasbon->code . ' diposting.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(Request $request, int $id)
    {
        $kasbon = Kasbon::findOrFail($id);
        try {
            $this->service->void($kasbon, $request->input('void_reason'));
            return redirect(list_url('sdm.kasbon.index'))
                ->with('success', 'Kasbon ' . $kasbon->code . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        $kasbon = Kasbon::findOrFail($id);
        if (! $kasbon->isDraft()) {
            return back()->with('error', 'Hanya draft yang bisa dihapus. Gunakan Void untuk membatalkan kasbon aktif.');
        }
        $kasbon->delete();
        return redirect(list_url('sdm.kasbon.index'))->with('success', 'Kasbon draft dihapus.');
    }

    protected function validateData(Request $request): array
    {
        $request->validate([
            'karyawan_id'          => 'required|exists:sdm_karyawan,id',
            'tanggal_pengajuan'    => 'required|date',
            'jumlah_pinjaman'      => 'required|string',
            'cicilan_per_bulan'    => 'required|string',
            'tanggal_mulai_potong' => 'nullable|date',
            'cash_account_id'      => 'nullable|exists:accounts,id',
            'notes'                => 'nullable|string',
        ]);
        return [
            'karyawan_id'          => (int) $request->karyawan_id,
            'tanggal_pengajuan'    => $request->tanggal_pengajuan,
            'jumlah_pinjaman'      => (float) clean_number($request->jumlah_pinjaman),
            'cicilan_per_bulan'    => (float) clean_number($request->cicilan_per_bulan),
            'tanggal_mulai_potong' => $request->tanggal_mulai_potong ?: null,
            'cash_account_id'      => $request->cash_account_id ?: null,
            'notes'                => $request->notes,
        ];
    }

    protected function cashAccounts()
    {
        return Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->orderBy('code')->get();
    }
}
