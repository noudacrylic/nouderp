<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\MarketplaceSettlement;
use App\Modules\Finance\Services\MarketplaceSettlementService;
use App\Models\MarketplaceConfig;
use Illuminate\Http\Request;

class MarketplaceSettlementController extends Controller
{
    public function __construct(protected MarketplaceSettlementService $service) {}

    public function index(Request $request)
    {
        $items = MarketplaceSettlement::with('marketplaceConfig.customer')
            ->withCount([
                'lines as lines_total',
                'lines as lines_unmatched' => fn($q) => $q->where('is_matched', false),
            ])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->marketplace_config_id, fn($q, $m) => $q->whereIn('marketplace_config_id', is_array($m) ? $m : explode(',', $m)))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->has_pending, function ($q) {
                $q->where('status', 'draft')
                  ->whereHas('lines', fn($qq) => $qq->where('is_matched', false));
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $configs = MarketplaceConfig::with('customer')->where('is_active', 1)->get();

        // Per-marketplace status untuk bulan terpilih. Default: bulan TRANSAKSI marketplace
        // TERTUA yang fakturnya belum direkonsiliasi (belum masuk rekonsiliasi POSTED) —
        // supaya bulan lama tetap tampil walau belum ada rekonsiliasi dibuat (mis. Juni
        // belum direkonsiliasi walau sekarang Juli). Begitu semua faktur bulan itu sudah
        // di-post → default pindah ke bulan berikutnya / bulan berjalan. Tetap bisa diganti
        // manual via picker (status_month).
        $statusMonth = $request->input('status_month');
        if (!$statusMonth || !preg_match('/^\d{4}-\d{2}$/', $statusMonth)) {
            $mpCustomerIds = $configs->pluck('customer_id')->filter()->all();
            $reconciledInvoiceIds = \App\Modules\Finance\Models\MarketplaceSettlementLine::whereNotNull('sales_invoice_id')
                ->whereHas('settlement', fn($q) => $q->where('status', 'posted'))
                ->pluck('sales_invoice_id');
            $oldestUnreconciled = ! empty($mpCustomerIds)
                ? \App\Models\SalesInvoice::whereIn('customer_id', $mpCustomerIds)
                    ->where('status', 'posted')
                    ->whereNotIn('id', $reconciledInvoiceIds)
                    ->min('invoice_date')
                : null;
            $statusMonth = $oldestUnreconciled
                ? \Illuminate\Support\Carbon::parse($oldestUnreconciled)->format('Y-m')
                : now()->format('Y-m');
        }
        [$sy, $sm] = array_map('intval', explode('-', $statusMonth));

        // Aggregate per config: count posted/draft this month + latest activity
        $thisMonth = MarketplaceSettlement::whereYear('date', $sy)
            ->whereMonth('date', $sm)
            ->whereIn('status', ['draft', 'posted'])
            ->get(['id', 'marketplace_config_id', 'status', 'date', 'total_net']);

        $latestPerConfig = MarketplaceSettlement::selectRaw('marketplace_config_id, MAX(date) AS latest_date')
            ->where('status', 'posted')
            ->groupBy('marketplace_config_id')
            ->pluck('latest_date', 'marketplace_config_id');

        // Gabungkan config yang BERBAGI akun wallet+hold jadi satu "toko" (mis. TikTok+Tokopedia
        // pasca-merger). Config tanpa akun lengkap berdiri sendiri.
        $groups = $configs->groupBy(fn($c) => ($c->account_wallet_id && $c->account_receivable_hold_id)
            ? 'grp-' . $c->account_wallet_id . '-' . $c->account_receivable_hold_id
            : 'solo-' . $c->id);

        $settlementStatuses = $groups->map(function ($groupConfigs) use ($thisMonth, $latestPerConfig) {
            $ids    = $groupConfigs->pluck('id')->values();
            $rows   = $thisMonth->whereIn('marketplace_config_id', $ids->all());
            $posted = $rows->where('status', 'posted');
            $draft  = $rows->where('status', 'draft');
            $status = $posted->count() > 0 ? 'posted' : ($draft->count() > 0 ? 'draft' : 'none');
            $names  = $groupConfigs->map(fn($c) => $c->customer->name ?? ('#' . $c->id))->unique()->values();
            // 1 config → nama penuh. Gabungan (>1) → kata pertama tiap toko dirangkai
            // (mis. "Tiktok Shop" + "Tokopedia" → "Tiktok Tokopedia").
            $name   = $groupConfigs->count() > 1
                ? $names->map(fn($n) => strtok($n, ' '))->unique()->implode(' ')
                : $names->first();
            $latest = $groupConfigs->map(fn($c) => $latestPerConfig->get($c->id))->filter()->max();
            return [
                'config_ids'   => $ids->all(),
                'name'         => $name,
                'status'       => $status,
                'posted_count' => $posted->count(),
                'draft_count'  => $draft->count(),
                'total_net'    => (float) $rows->sum('total_net'),
                'latest_date'  => $latest,
            ];
        })->values();

        // Map config_id → nama toko gabungan (dipakai kolom Marketplace di tabel history & filter).
        $configGroupName = [];
        foreach ($settlementStatuses as $g) {
            foreach ($g['config_ids'] as $cid) $configGroupName[$cid] = $g['name'];
        }

        return view('erp.finance.marketplace-settlements.index', compact(
            'items', 'configs', 'statusMonth', 'settlementStatuses', 'configGroupName'
        ));
    }

    public function create(Request $request)
    {
        // Form pakai format baku — tidak perlu pilih marketplace dulu, deteksi dari kolom Excel.
        $prefillDate = $request->input('date');

        // Daftar invoice marketplace yang belum di-settlement (belum match ke line settlement draft/posted).
        $marketplaceCustomerIds = MarketplaceConfig::where('is_active', 1)->pluck('customer_id');

        $settledInvoiceIds = \App\Modules\Finance\Models\MarketplaceSettlementLine::whereNotNull('sales_invoice_id')
            ->whereHas('settlement', fn($q) => $q->whereIn('status', ['draft', 'posted']))
            ->pluck('sales_invoice_id');

        $pendingInvoices = \App\Models\SalesInvoice::with('customer', 'salesOrder')
            ->whereIn('customer_id', $marketplaceCustomerIds)
            ->where('status', 'posted')
            ->whereNotIn('id', $settledInvoiceIds)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $universalPrompt = $this->readUniversalPrompt();

        return view('erp.finance.marketplace-settlements.create', compact(
            'prefillDate',
            'pendingInvoices',
            'universalPrompt'
        ));
    }

    protected function readUniversalPrompt(): string
    {
        $path = resource_path('marketplace-prompts/universal.txt');
        return file_exists($path) ? file_get_contents($path) : '';
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'  => 'required|date',
            'file'  => 'required|file|mimes:xlsx,xls,csv',
            'notes' => 'nullable|string',
        ]);

        try {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
            $parsed = $this->service->parseStandardFormat($file->getRealPath(), $ext);

            if ($parsed['total_rows'] === 0) {
                $msg = 'Tidak ada baris valid yang bisa diparse.';
                if (!empty($parsed['unmapped'])) {
                    $msg .= ' Marketplace yang tidak dikenal: ' . implode(', ', $parsed['unmapped'])
                        . '. Pastikan ada Marketplace Config aktif dengan customers.marketplace_code yang sesuai.';
                }
                return back()->withInput()->with('error', $msg);
            }

            // Rekonsiliasi WAJIB per-bulan: semua transaksi dalam satu file harus di bulan yang
            // sama, supaya jurnal penyesuaian tidak mencampur bulan (mis. transaksi Juni ikut
            // terbukukan di Juli). Bulan jurnal diambil dari settlement_date Excel, bukan tanggal upload.
            $months = [];
            foreach ($parsed['rows_per_config'] as $rows) {
                foreach ($rows as $r) {
                    if (!empty($r['settlement_date'])) {
                        $months[substr($r['settlement_date'], 0, 7)] = true;
                    }
                }
            }
            if (count($months) > 1) {
                $list = array_keys($months);
                sort($list);
                return back()->withInput()->with('error',
                    'File berisi transaksi lintas bulan (' . implode(', ', $list) . '). '
                    . 'Rekonsiliasi harus per-bulan — pisahkan file per bulan lalu upload satu per satu.');
            }

            $created = $this->service->createDraftsFromStandard($parsed['rows_per_config'], [
                'date'            => $data['date'],
                'source_filename' => $file->getClientOriginalName(),
                'notes'           => $data['notes'] ?? null,
            ]);

            $count = count($created);
            $numbers = collect($created)->pluck('number')->implode(', ');
            $msg = "$count rekonsiliasi draft dibuat ($numbers). Total {$parsed['total_rows']} baris.";
            if (!empty($parsed['unmapped'])) {
                $msg .= ' ⚠ Marketplace tidak dikenal di-skip: ' . implode(', ', $parsed['unmapped']);
            }

            // Kalau cuma 1 settlement, langsung ke detail-nya
            if ($count === 1) {
                return redirect()->route('finance.cash-bank.settlements.show', $created[0]->id)
                    ->with('success', $msg);
            }
            return redirect(list_url('finance.cash-bank.settlements.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $ms = MarketplaceSettlement::with(['marketplaceConfig.customer', 'lines.salesInvoice'])->findOrFail($id);
        return view('erp.finance.marketplace-settlements.show', compact('ms'));
    }

    public function destroy($id)
    {
        $ms = MarketplaceSettlement::findOrFail($id);
        if (!$ms->isDraft()) return back()->with('error', 'Hanya draft yang bisa dihapus.');
        $ms->lines()->delete();
        $ms->delete();
        return back()->with('success', 'Draft dihapus.');
    }

    public function post($id)
    {
        $ms = MarketplaceSettlement::findOrFail($id);
        try {
            $result = $this->service->splitMatchedAndPost($ms);
            $posted  = $result['posted'];
            $pending = $result['pending'];

            $msg = 'Rekonsiliasi ' . $posted->number . ' POSTED ('
                . $posted->lines()->count() . ' baris matched dijurnal).';
            if ($pending) {
                $msg .= ' Sisa ' . $pending->lines()->count() . ' baris dipindah ke '
                    . $pending->number . ' (DRAFT, akan auto-match saat invoice baru dibuat).';
            }
            return redirect(list_url('finance.cash-bank.settlements.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Manual trigger: re-run matching untuk semua line unmatched di settlement draft.
     * Logic sama dengan auto-rematch yang berjalan saat invoice baru dibuat.
     */
    public function retryMatch($id)
    {
        $ms = MarketplaceSettlement::findOrFail($id);
        try {
            $newly = $this->service->retryMatch($ms);
            if ($newly === 0) {
                return back()->with('error', 'Tidak ada line yang ketemu match-nya. Cek dulu: invoice utk customer ini sudah ada & customer_po_number sesuai order_ref settlement.');
            }
            return back()->with('success', "$newly baris baru ketemu match-nya.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Hapus semua baris "tidak match" (fee aktual 0, order belum tercatat di ERP) dari draft.
     * Untuk rekonsiliasi awal — sisa transaksi ini dimasukkan lewat Opening Balance saldo.
     */
    public function deleteUnmatched($id)
    {
        $ms = MarketplaceSettlement::findOrFail($id);
        try {
            $deleted = $this->service->deleteUnmatchedLines($ms);
            if ($deleted === 0) {
                return back()->with('error', 'Tidak ada baris tidak match untuk dihapus.');
            }
            // Kalau draft jadi kosong (tidak ada baris matched tersisa), hapus draft sekalian.
            if ($ms->fresh()->lines()->count() === 0) {
                $ms->delete();
                return redirect(list_url('finance.cash-bank.settlements.index'))
                    ->with('success', "$deleted baris tidak match dihapus. Rekonsiliasi kosong, draft ikut dihapus.");
            }
            return back()->with('success', "$deleted baris tidak match dihapus. Sisa baris matched siap di-submit.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $ms = MarketplaceSettlement::findOrFail($id);
        if (!$ms->canBeVoided()) return back()->with('error', 'Tidak bisa di-void.');
        try {
            $this->service->void($ms);
            return back()->with('success', 'Rekonsiliasi ' . $ms->number . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

}
