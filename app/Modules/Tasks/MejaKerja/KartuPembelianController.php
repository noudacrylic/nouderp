<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Services\BankTransferService;
use App\Modules\Notifications\Services\PusatNotifikasi;
use App\Modules\Purchasing\Models\PengajuanBayarOwner;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PembayaranOwnerService;
use App\Modules\Purchasing\Services\SelesaikanPesananService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Skills\BayarOwnerSkill;
use App\Modules\Tasks\MejaKerja\Skills\IsiSaldoSkill;
use App\Modules\Tasks\MejaKerja\Skills\SelesaikanPesananSkill;
use App\Modules\Tasks\Models\Task;
use App\Services\FotoNotaService;
use Illuminate\Http\Request;

/** Tombol uang di kartu Meja Kerja: bayar pembelian & isi saldo (owner), selesaikan pesanan (admin). */
class KartuPembelianController extends Controller
{
    public function __construct(private MejaKerjaService $meja) {}

    public function bayar(Request $request, Task $task, PembayaranOwnerService $layanan, PusatNotifikasi $pusat)
    {
        abort_unless($this->owner($request->user()), 403, 'Hanya owner yang membayar lewat kartu ini.');
        abort_unless($task->skill === 'bayar-owner', 404);
        $request->validate(FotoNotaService::rules(), FotoNotaService::messages());
        [$rekening, $nominal] = $this->isian($request);

        $p = PengajuanBayarOwner::findOrFail($task->taskable_id);
        try {
            $payment = $layanan->bayar($p, $rekening, $nominal, array_filter((array) $request->file('nota', [])), $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $skill = app(BayarOwnerSkill::class);
        $this->meja->sinkronkanSkill($skill);
        $task->refresh();
        if ($task->status === 'open') $skill->jadikanHutang($task, $request->user());

        if ($p->diajukan_oleh) {
            $pusat->keUser($p->pengaju, 'bayar_owner', 'Owner sudah membayar', $layanan->nomor($p) . ': Rp' . number_format($nominal, 0, ',', '.'));
        }

        return back()->with('success', "Pembayaran {$payment->payment_number} tercatat.");
    }

    public function isiSaldo(Request $request, Task $task, BankTransferService $transfer)
    {
        abort_unless($this->owner($request->user()), 403, 'Hanya owner yang mengisi saldo.');
        abort_unless($task->skill === 'isi-saldo', 404);
        [$rekening, $nominal] = $this->isian($request);

        try {
            $bt = $transfer->createDraft([
                'date'            => today()->toDateString(),
                'from_account_id' => $rekening,
                'to_account_id'   => Account::where('code', IsiSaldoSkill::AKUN)->value('id'),
                'amount'          => $nominal,
                'notes'           => 'Isi saldo ShopeePay dari Meja Owner',
            ]);
            $transfer->post($bt);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->meja->sinkronkanSkill(app(IsiSaldoSkill::class));

        return back()->with('success', "Transfer {$bt->number} tercatat.");
    }

    public function selesaikan(Request $request, Task $task, SelesaikanPesananService $layanan)
    {
        abort_unless($task->skill === 'selesaikan-pesanan', 404);
        $u = $request->user();
        abort_unless($u->role === 'super_admin' || (int) $task->assignee_user_id === (int) $u->id
            || in_array($task->peran, $this->meja->peranUser($u->id), true), 403, 'Task ini milik peran lain.');

        try {
            $faktur = $layanan->selesaikan(PurchaseOrder::findOrFail($task->taskable_id));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->meja->sinkronkanSkill(app(SelesaikanPesananSkill::class));

        return back()->with('success', "Faktur {$faktur->invoice_number} dibuat dan stok masuk. Kalau ada barang kurang atau rusak, buat retur dari faktur ini.");
    }

    /** @return array{0: int, 1: float} rekening owner & nominal */
    private function isian(Request $request): array
    {
        $data = $request->validate([
            'rekening' => 'required|integer|exists:accounts,id',
            'nominal'  => 'required',
        ]);
        abort_unless(MejaKerjaService::rekeningOwner()->whereKey($data['rekening'])->exists(), 422, 'Rekening ini bukan rekening owner.');

        return [(int) $data['rekening'], (float) clean_number($data['nominal'])];
    }

    private function owner(User $u): bool
    {
        return $u->role === 'super_admin' || MejaKerjaPeran::where('user_id', $u->id)->where('peran', 'owner')->exists();
    }
}
