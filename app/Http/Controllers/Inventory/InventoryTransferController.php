<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\FifoService;
use Illuminate\Support\Facades\DB;

class InventoryTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryTransfer::query();

        $search = trim((string) $request->input('search', $request->input('q', '')));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%$search%")
                    ->orWhereHas('items.product', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%$search%")
                            ->orWhere('sku', 'like', "%$search%");
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('erp.inventory.transfers.index', compact('transfers'));
    }

    public function create()
    {
        $warehouses = Warehouse::where('is_active', 1)->get();
        $products = Product::where('is_active', 1)
            ->orderBy('name')
            ->get();

        return view(
            'erp.inventory.transfers.create',
            compact('products', 'warehouses')
        );
    }

    public function store(Request $request)
    {
        DB::transaction(function () use ($request) {
            $transfer = InventoryTransfer::create([
                'number' => 'TRF-' . time(),
                'date' => $request->date,
                'from_warehouse_id' => $request->from_warehouse_id,
                'to_warehouse_id' => $request->to_warehouse_id,
                'status' => 'draft'
            ]);

            if ($request->products) {
                foreach ($request->products as $index => $productId) {
                    if ($productId && $request->qty[$index] > 0) {
                        InventoryTransferItem::create([
                            'transfer_id' => $transfer->id,
                            'product_id' => $productId,
                            'qty' => $request->qty[$index]
                        ]);
                    }
                }
            }
        });

        return redirect()
            ->route('inventory.transfers.index')
            ->with('success', 'Transfer created');
    }

    public function productStock($product, $warehouse)
    {
        $stock = ProductStock::where('product_id', $product)
            ->where('warehouse_id', $warehouse)
            ->value('qty_on_hand') ?? 0;

        return response()->json([
            'stock' => (float) $stock
        ]);
    }

    public function edit($id)
    {
        $transfer = InventoryTransfer::with('items.product')->findOrFail($id);
        $warehouses = Warehouse::where('is_active', 1)->get();
        $products = Product::where('is_active', 1)
            ->orderBy('name')
            ->get();

        return view('erp.inventory.transfers.edit', compact('transfer', 'products', 'warehouses'));
    }

    public function show($id)
    {
        $transfer = InventoryTransfer::with('items.product')->findOrFail($id);

        return view('erp.inventory.transfers.show', compact('transfer'));
    }

    public function update(Request $request, $id)
    {
        DB::transaction(function () use ($request, $id) {
            $transfer = InventoryTransfer::findOrFail($id);

            if ($transfer->status !== 'draft') {
                throw new \Exception("Cannot edit posted transfer.");
            }

            $transfer->update([
                'date' => $request->date,
                'from_warehouse_id' => $request->from_warehouse_id,
                'to_warehouse_id' => $request->to_warehouse_id,
            ]);

            $transfer->items()->delete();

            if ($request->products) {
                foreach ($request->products as $index => $productId) {
                    if ($productId && $request->qty[$index] > 0) {
                        InventoryTransferItem::create([
                            'transfer_id' => $transfer->id,
                            'product_id' => $productId,
                            'qty' => $request->qty[$index]
                        ]);
                    }
                }
            }
        });

        return redirect()
            ->route('inventory.transfers.index')
            ->with('success', 'Transfer updated');
    }

    public function post($id)
    {
        DB::transaction(function () use ($id) {
            $transfer = InventoryTransfer::with('items')->findOrFail($id);

            if ($transfer->status !== 'draft') {
                throw new \Exception("Transfer already posted.");
            }

            $engine = app(InventoryEngine::class);
            $fifo = app(FifoService::class);

            foreach ($transfer->items as $item) {
                // 1. Credit Source
                $engine->ledger(
                    $item->product_id,
                    $transfer->from_warehouse_id,
                    0,
                    $item->qty,
                    'transfer_out',
                    $transfer->number,
                    null,
                    $transfer->id
                );

                // 2. Debit Target
                $engine->ledger(
                    $item->product_id,
                    $transfer->to_warehouse_id,
                    $item->qty,
                    0,
                    'transfer_in',
                    $transfer->number,
                    null,
                    $transfer->id
                );

                // 3. Move FIFO Layers
                $fifo->moveLayer(
                    $item->product_id,
                    $transfer->from_warehouse_id,
                    $transfer->to_warehouse_id,
                    $item->qty
                );
            }

            $transfer->update([
                'status' => 'posted'
            ]);
        });

        return redirect(list_url('inventory.transfers.index'))->with('success', 'Transfer posted successfully');
    }

    public function destroy($id)
    {
        InventoryTransfer::findOrFail($id)->delete();

        return back()->with('success', 'Transfer deleted');
    }
    public function void($id)
    {

        $transfer = InventoryTransfer::with('items')->findOrFail($id);

        if ($transfer->status != 'posted') {
            return back()->with('error', 'Only posted transfer can be voided');
        }

        $engine = app(\App\Core\Inventory\InventoryEngine::class);
        $fifo = app(\App\Core\Inventory\FifoService::class);

        foreach ($transfer->items as $item) {

            // reverse ledger

            $engine->ledger(
                $item->product_id,
                $transfer->from_warehouse_id,
                $item->qty,
                0,
                'transfer_void',
                $transfer->number,
                null,
                $transfer->id
            );

            $engine->ledger(
                $item->product_id,
                $transfer->to_warehouse_id,
                0,
                $item->qty,
                'transfer_void',
                $transfer->number,
                null,
                $transfer->id
            );


            // reverse fifo

            $fifo->moveLayer(
                $item->product_id,
                $transfer->to_warehouse_id,
                $transfer->from_warehouse_id,
                $item->qty
            );

        }

        $transfer->update([
            'status' => 'void'
        ]);

        return back()->with('success', 'Transfer voided successfully');

    }
}
