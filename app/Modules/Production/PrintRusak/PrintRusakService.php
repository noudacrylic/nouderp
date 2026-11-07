<?php

namespace App\Modules\Production\PrintRusak;

use App\Core\Accounting\Account;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\ProductStock;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Enums\AccountCodeEnum;
use App\Models\InventoryCostLayer;
use App\Models\User;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Print rusak (keputusan 7 Nov 2026): lembar bahan print yang rusak — kebanyakan paperjam —
 * langsung dikeluarkan dari stok (FIFO) dan dibebankan ke 5007 Beban Overhead Produksi, yang
 * termasuk HPP di Laba Rugi. SENGAJA tidak ditempel ke WIP OP: paperjam kerusakan mesin, bukan
 * karena produknya, dan print kadang terjadi sebelum OP ada. OP/SO hanya keterangan untuk rekap.
 */
class PrintRusakService
{
    public const LEDGER = 'print_rusak';
    public const LEDGER_VOID = 'print_rusak_void';

    /** @return Collection<int, \App\Core\Inventory\Product> bahan yang boleh dilaporkan */
    public function bahan(): Collection
    {
        return \App\Core\Inventory\Product::whereIn('id', DB::table('print_rusak_bahan')->select('product_id'))
            ->orderBy('name')->get(['id', 'sku', 'name', 'base_unit']);
    }

    /** Gudang dengan stok terbanyak untuk bahan ini — bawaan form. */
    public function gudangBawaan(int $productId): ?int
    {
        return ProductStock::where('product_id', $productId)->where('qty_on_hand', '>', 0)
            ->orderByDesc('qty_on_hand')->value('warehouse_id');
    }

    /**
     * @param  array{product_id:int, warehouse_id:int, qty:float, sebab:string, catatan?:?string, production_order_id?:?int, sales_order_id?:?int, tanggal?:?string}  $d
     */
    public function catat(array $d, ?User $oleh): PrintRusak
    {
        if (!DB::table('print_rusak_bahan')->where('product_id', $d['product_id'])->exists()) {
            throw new \DomainException('Bahan ini tidak terdaftar sebagai bahan print.');
        }
        $qty = (float) $d['qty'];
        $stok = (float) ProductStock::where('product_id', $d['product_id'])->where('warehouse_id', $d['warehouse_id'])->value('qty_on_hand');
        if ($qty > $stok + 1e-9) {
            throw new \DomainException('Stok di gudang itu hanya ' . rtrim(rtrim(number_format($stok, 2, ',', '.'), '0'), ',') . ' — periksa gudang atau jumlahnya.');
        }

        return DB::transaction(function () use ($d, $qty, $oleh) {
            $pr = PrintRusak::create([
                'nomor'               => NumberGeneratorService::generate('PRR'),
                'tanggal'             => $d['tanggal'] ?? now()->toDateString(),
                'product_id'          => $d['product_id'],
                'warehouse_id'        => $d['warehouse_id'],
                'qty'                 => $qty,
                'sebab'               => $d['sebab'],
                'catatan'             => $d['catatan'] ?? null,
                'production_order_id' => $d['production_order_id'] ?? null,
                'sales_order_id'      => $d['sales_order_id'] ?? null,
                'user_id'             => $oleh?->id,
            ]);

            app(InventoryEngine::class)->ledger(
                productId: $pr->product_id,
                warehouseId: $pr->warehouse_id,
                qtyIn: 0,
                qtyOut: $qty,
                type: self::LEDGER,
                reference: $pr->nomor,
                notes: $pr->labelSebab(),
                transactionId: $pr->id
            );
            $nilai = (float) app(FifoService::class)->consume($pr->product_id, $pr->warehouse_id, $qty, self::LEDGER, $pr->id);
            $pr->update(['nilai' => round($nilai, 2)]);

            if ($nilai > 0) {
                $this->jurnal($pr, $nilai, false);
            }

            return $pr;
        });
    }

    /** Batal: stok kembali pada biaya semula + jurnal balik. */
    public function batal(PrintRusak $pr, ?User $oleh): void
    {
        DB::transaction(function () use ($pr, $oleh) {
            $pr = PrintRusak::lockForUpdate()->findOrFail($pr->id);
            if ($pr->isVoided()) throw new \DomainException('Laporan ini sudah dibatalkan.');

            $keluar = InventoryCostLayer::where('reference_type', self::LEDGER)->where('reference_id', $pr->id)
                ->where('product_id', $pr->product_id)->get();
            $qty  = (float) $keluar->sum('qty_out') ?: (float) $pr->qty;
            $biaya = (float) $keluar->sum(fn ($c) => (float) $c->qty_out * (float) $c->unit_cost);

            app(InventoryEngine::class)->ledger(
                productId: $pr->product_id,
                warehouseId: $pr->warehouse_id,
                qtyIn: $qty,
                qtyOut: 0,
                type: self::LEDGER_VOID,
                reference: $pr->nomor,
                notes: 'Batal print rusak',
                transactionId: $pr->id
            );
            app(FifoService::class)->createLayer($pr->product_id, $pr->warehouse_id, $qty, $qty > 0 ? $biaya / $qty : 0, self::LEDGER_VOID, $pr->id);

            if ($biaya > 0) {
                $this->jurnal($pr, $biaya, true);
            }

            $pr->update(['voided_at' => now(), 'voided_by' => $oleh?->id]);
        });
    }

    private function jurnal(PrintRusak $pr, float $nilai, bool $balik): void
    {
        $beban = Account::where('code', AccountCodeEnum::PRODUCTION_OVERHEAD)->firstOrFail();
        $persediaan = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();
        $nama = $pr->product?->name ?? 'bahan print';
        $ket = ($balik ? 'Batal print rusak' : 'Print rusak') . " {$pr->nomor} — " . rtrim(rtrim(number_format($pr->qty, 2, ',', '.'), '0'), ',') . " {$nama} ({$pr->labelSebab()})";

        app(JournalPostingService::class)->post(new JournalEntryDTO(
            date:             ($balik ? now() : $pr->tanggal)->format('Y-m-d'),
            reference_type:   $balik ? self::LEDGER_VOID : self::LEDGER,
            reference_id:     $pr->id,
            description:      $ket,
            lines: [
                new JournalLineDTO(account_id: $balik ? $persediaan->id : $beban->id, debit: $nilai, credit: 0, description: $ket),
                new JournalLineDTO(account_id: $balik ? $beban->id : $persediaan->id, debit: 0, credit: $nilai, description: $ket),
            ],
            reference_number: $pr->nomor
        ));
    }

    /**
     * Rekap satu bulan per bahan: lembar rusak, nilai, pemakaian bahan total (produksi + rusak),
     * dan persentase rusak — dasar faktor susut BOM. Plus jumlah per sebab.
     *
     * @return array{bahan: Collection, sebab: Collection}
     */
    public function rekap(int $tahun, int $bulan): array
    {
        $awal = \Carbon\Carbon::create($tahun, $bulan, 1)->startOfDay();
        $akhir = $awal->copy()->endOfMonth();

        $aktif = PrintRusak::whereNull('voided_at')->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()]);

        $rusak = (clone $aktif)->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty) qty, SUM(nilai) nilai, COUNT(*) n')->get()->keyBy('product_id');

        // Pemakaian produksi bersih bulan ini (bahan OP + tambahan − batal) untuk pembanding.
        $pakai = DB::table('inventory_ledgers')
            ->whereIn('product_id', $rusak->keys()->all() ?: [0])
            ->whereBetween('created_at', [$awal, $akhir])
            ->whereIn('transaction_type', ['production_material', 'production_material_addition', 'production_material_addition_void', 'production_cancel'])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_out) - SUM(qty_in) qty')->pluck('qty', 'product_id');

        $produk = \App\Core\Inventory\Product::whereIn('id', $rusak->keys())->get(['id', 'name', 'base_unit'])->keyBy('id');

        $bahan = $rusak->map(function ($r) use ($pakai, $produk) {
            $prod = max(0, (float) ($pakai[$r->product_id] ?? 0));
            $total = $prod + (float) $r->qty;
            return (object) [
                'nama'     => $produk[$r->product_id]->name ?? '#' . $r->product_id,
                'satuan'   => $produk[$r->product_id]->base_unit ?? 'lembar',
                'rusak'    => (float) $r->qty,
                'laporan'  => (int) $r->n,
                'nilai'    => (float) $r->nilai,
                'produksi' => $prod,
                'persen'   => $total > 0 ? round((float) $r->qty / $total * 100, 1) : null,
            ];
        })->values();

        $sebab = (clone $aktif)->groupBy('sebab')
            ->selectRaw('sebab, SUM(qty) qty, COUNT(*) n, SUM(nilai) nilai')->orderByDesc('qty')->get();

        return ['bahan' => $bahan, 'sebab' => $sebab];
    }
}
