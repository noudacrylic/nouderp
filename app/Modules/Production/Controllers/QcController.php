<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\QcCheck;
use App\Modules\Production\Models\QcProfile;
use App\Modules\Production\Services\QcService;
use Illuminate\Http\Request;

/**
 * Layar Timbang QC: pilih OP → produk → timbang per bungkus.
 * Acuan & toleransi ada di QcAcuanController (khusus admin).
 */
class QcController extends Controller
{
    public function __construct(private QcService $qc) {}

    public function index(Request $request)
    {
        $produkQc = QcProfile::where('is_active', true)->pluck('product_id');

        // OP berjalan yang punya hasil ber-profil QC.
        $orders = ProductionOrder::with(['outputs.product:id,sku,name', 'salesOrder:id,order_number'])
            ->whereIn('status', QcService::STATUS_BOLEH_TIMBANG)
            ->whereHas('outputs', fn ($q) => $q->whereIn('product_id', $produkQc))
            ->latest('id')
            ->limit(100)
            ->get();

        $order = $request->filled('op') ? $orders->firstWhere('id', (int) $request->op) : null;

        $produkPilihan = $order
            ? $order->outputs->filter(fn ($o) => $produkQc->contains($o->product_id))->values()
            : collect();

        $productId = (int) $request->input('produk', $produkPilihan->count() === 1 ? $produkPilihan->first()->product_id : 0);
        $output    = $produkPilihan->firstWhere('product_id', $productId);

        $profil = $output ? $this->qc->profilAktif($productId) : null;
        $ringkasan = $output ? $this->qc->ringkasan($order, $productId) : null;
        $riwayat = $output
            ? QcCheck::with('user:id,name')->where('production_order_id', $order->id)->where('product_id', $productId)
                ->latest('id')->limit(30)->get()
            : collect();

        // Langkah QC OP ini (timer) + calon penimbang dari divisi pelaksananya.
        $langkah = $order ? $order->steps()->with(['department.selectableExecutors', 'executors'])->where('is_qc', true)->first() : null;
        $produksiSelesai = $order
            ? !$order->steps()->where('is_qc', false)->where('status', '!=', 'completed')->exists()
            : false;

        return view('erp.production.qc.index', [
            'orders'        => $orders,
            'order'         => $order,
            'produkPilihan' => $produkPilihan,
            'productId'     => $output ? $productId : null,
            'profil'        => $profil,
            'target'        => $profil ? $this->qc->target($profil) : null,
            'toleransi'     => $profil ? $this->qc->toleransiGram($profil) : null,
            'wajib'         => $output ? $this->qc->wajibQc($order, $productId) : false,
            'ringkasan'     => $ringkasan,
            'riwayat'       => $riwayat,
            'langkah'       => $langkah,
            'penimbang'     => $langkah?->department?->selectableExecutors->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values() ?? collect(),
            'produksiSelesai' => $produksiSelesai,
            'isAdmin'       => in_array($request->user()?->role, ['super_admin', 'admin'], true),
        ]);
    }

    /** Satu penimbangan (JSON) — hasil dihitung server dari acuan. */
    public function timbang(Request $request)
    {
        $data = $request->validate([
            'production_order_id' => 'required|integer|exists:production_orders,id',
            'product_id'          => 'required|integer|exists:products,id',
            'gram'                => 'required|numeric|min:0.1|max:100000',
            'mode'                => 'required|in:normal,perbaikan',
            'source'              => 'required|in:timbangan,simulasi,manual',
            'raw'                 => 'nullable|string|max:4000',
            'executor_ids'        => 'nullable|array',
            'executor_ids.*'      => 'integer|exists:production_department_executors,id',
        ]);

        try {
            $check = $this->qc->catatTimbang($data['production_order_id'], $data['product_id'], (float) $data['gram'],
                $data['mode'], $data['source'], $data['raw'] ?? null, $request->user()?->id, $data['executor_ids'] ?? []);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'pesan' => $e->getMessage()], 422);
        }

        $order = ProductionOrder::with('outputs')->find($data['production_order_id']);
        $langkah = $order->steps()->where('is_qc', true)->first();

        return response()->json([
            'success'   => true,
            'check'     => [
                'id'        => $check->id,
                'result'    => $check->result,
                'aktual'    => $check->actual_gram,
                'target'    => $check->target_gram,
                'toleransi' => $check->tolerance_gram,
                'selisih'   => $check->diff_gram,
                'pesan'     => $check->analysis['pesan'] ?? '',
                'kandidat'  => $check->analysis['kandidat'] ?? [],
                'mode'      => $check->mode,
            ],
            'ringkasan' => $this->qc->ringkasan($order, $data['product_id']),
            'timer'     => $langkah?->status,
            'jeda'      => $this->qc->jedaOtomatis,
        ]);
    }

    /** Pelepasan QC darurat (mis. timbangan rusak) — admin saja, alasan wajib. */
    public function lepas(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['super_admin', 'admin'], true), 403, 'Hanya admin yang boleh melepas QC.');

        $data = $request->validate([
            'production_order_id' => 'required|integer|exists:production_orders,id',
            'product_id'          => 'required|integer|exists:products,id',
            'qty'                 => 'required|integer|min:1|max:100000',
            'reason'              => 'required|string|max:255',
        ]);

        try {
            $this->qc->lepas($data['production_order_id'], $data['product_id'], $data['qty'], $data['reason'], $request->user()?->id);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['qty'] . ' bungkus dilepas tanpa timbang. Alasan tercatat.');
    }
}
