<?php

namespace App\Modules\Production\PrintRusak;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Halaman "Print rusak" di Meja Kerja: form lapor, rekap bulanan, riwayat, dan (super admin)
 * daftar bahan print. Dibuka dari tombol Meja Kerja, kartu Cetak stiker, dan halaman OP.
 */
class PrintRusakController extends Controller
{
    public function __construct(private PrintRusakService $svc) {}

    public function index(Request $request)
    {
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('bulan')) ? $request->input('bulan') : now()->format('Y-m');
        [$th, $bl] = array_map('intval', explode('-', $bulan));

        $op = $request->integer('op') ? ProductionOrder::find($request->integer('op'), ['id', 'order_number', 'sales_order_id']) : null;
        $bahan = $this->svc->bahan();
        $gudangBawaan = $bahan->mapWithKeys(fn ($b) => [$b->id => $this->svc->gudangBawaan($b->id)]);
        $stok = DB::table('product_stocks')->whereIn('product_id', $bahan->pluck('id'))
            ->get(['product_id', 'warehouse_id', 'qty_on_hand'])
            ->groupBy('product_id')->map(fn ($r) => $r->pluck('qty_on_hand', 'warehouse_id'));

        return view('erp.tasks.meja.print-rusak', [
            'bahan'        => $bahan,
            'gudang'       => Warehouse::orderBy('name')->get(['id', 'name']),
            'gudangBawaan' => $gudangBawaan,
            'stok'         => $stok,
            'op'           => $op,
            'soBawaan'     => $op?->sales_order_id ? SalesOrder::whereKey($op->sales_order_id)->value('order_number') : null,
            'bulan'        => $bulan,
            'rekap'        => $this->svc->rekap($th, $bl),
            'riwayat'      => PrintRusak::with(['product:id,name,base_unit', 'warehouse:id,name', 'productionOrder:id,order_number', 'salesOrder:id,order_number', 'user:id,name'])
                ->latest('id')->paginate(per_page_size())->withQueryString(),
            'superAdmin'   => $request->user()->role === 'super_admin',
            'calonBahan'   => $request->user()->role === 'super_admin'
                ? Product::where('sale_type', 'ready')->whereNotIn('id', $bahan->pluck('id'))->orderBy('name')->get(['id', 'sku', 'name'])
                : collect(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'product_id'          => 'required|integer|exists:products,id',
            'warehouse_id'        => 'required|integer|exists:warehouses,id',
            'qty'                 => 'required',
            'sebab'               => 'required|in:' . implode(',', array_keys(PrintRusak::SEBAB)),
            'catatan'             => 'nullable|string|max:500',
            'production_order_id' => 'nullable|integer|exists:production_orders,id',
            'so'                  => 'nullable|string|max:60',
        ]);
        $d['qty'] = clean_number($d['qty']);
        if ($d['qty'] <= 0) return back()->withInput()->withErrors(['qty' => 'Jumlah lembar harus lebih dari 0.']);

        $so = trim((string) ($d['so'] ?? ''));
        if ($so !== '') {
            $d['sales_order_id'] = SalesOrder::where('order_number', $so)->orWhere('customer_po_number', $so)->value('id');
            if (!$d['sales_order_id']) return back()->withInput()->withErrors(['so' => "Pesanan {$so} tidak ditemukan."]);
        }

        try {
            $pr = $this->svc->catat($d, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('tasks.meja.print-rusak.index', array_filter(['op' => $d['production_order_id'] ?? null]))
            ->with('success', "{$pr->nomor} dicatat: stok {$pr->product->name} berkurang " . rtrim(rtrim(number_format($pr->qty, 2, ',', '.'), '0'), ',') . ' — nilai Rp' . number_format($pr->nilai, 0, ',', '.') . ' masuk HPP (Beban Overhead Produksi).');
    }

    public function void(Request $request, PrintRusak $printRusak)
    {
        abort_unless($printRusak->canBeVoided($request->user()), 403, 'Hanya pelapor (di hari yang sama) atau super admin yang boleh membatalkan.');
        try {
            $this->svc->batal($printRusak, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', "{$printRusak->nomor} dibatalkan — stok dikembalikan & jurnal dibalik.");
    }

    public function tambahBahan(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        $d = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        DB::table('print_rusak_bahan')->insertOrIgnore(['product_id' => $d['product_id'], 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', 'Bahan print ditambahkan.');
    }

    public function hapusBahan(Request $request, int $product)
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        DB::table('print_rusak_bahan')->where('product_id', $product)->delete();
        return back()->with('success', 'Bahan print dihapus dari daftar (laporan lama tetap ada).');
    }
}
