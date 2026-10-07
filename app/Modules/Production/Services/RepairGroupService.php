<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RepairGroup;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Kelompok Perbaikan: operator mengumpulkan barang Gudang Perbaikan (asal retur & opname)
 * yang HPP-nya sebanding, lalu satu kelompok dibuatkan tepat SATU OP Perbaikan.
 *
 * Kelompok hanya MEMESAN qty — stok tetap di Gudang Perbaikan dan tak ada jurnal. Pemesanan
 * itu yang membuat satu unit tak bisa masuk dua kelompok (lihat RepairQueueService::tersedia).
 *
 * Siklus status mengikuti OP-nya lewat syncFromOrder():
 *   terbuka → di_op (OP dibuat) → selesai (OP difinalisasi penutup)
 *   di_op → terbuka lagi bila OP dibatalkan; selesai → di_op bila batch penutup dibatalkan.
 */
class RepairGroupService
{
    public function __construct(private RepairQueueService $queue) {}

    /** @param array<int|string, float|int|string> $items product_id => qty */
    public function create(string $name, array $items, ?string $notes = null): RepairGroup
    {
        return DB::transaction(function () use ($name, $items, $notes) {
            $items = $this->validItems($items, null);

            $group = RepairGroup::create([
                'number'     => $this->nextNumber(),
                'name'       => trim($name),
                'status'     => RepairGroup::TERBUKA,
                'notes'      => $notes,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $pid => $qty) {
                $group->items()->create(['product_id' => $pid, 'qty' => $qty]);
            }

            return $group;
        });
    }

    /** Ganti isi kelompok. Qty 0 = barang dikeluarkan dari kelompok. */
    public function update(RepairGroup $group, string $name, array $items, ?string $notes = null): RepairGroup
    {
        return DB::transaction(function () use ($group, $name, $items, $notes) {
            $group = RepairGroup::lockForUpdate()->findOrFail($group->id);
            $this->assertTerbuka($group);

            $items = $this->validItems($items, $group->id);

            $group->update(['name' => trim($name), 'notes' => $notes]);
            $group->items()->whereNotIn('product_id', array_keys($items))->delete();
            foreach ($items as $pid => $qty) {
                $group->items()->updateOrCreate(['product_id' => $pid], ['qty' => $qty]);
            }

            return $group->refresh();
        });
    }

    public function cancel(RepairGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $group = RepairGroup::lockForUpdate()->findOrFail($group->id);
            $this->assertTerbuka($group);
            $group->update(['status' => RepairGroup::BATAL]);
        });
    }

    /**
     * Kaitkan kelompok ke OP yang baru dibuat. Dipanggil di DALAM transaksi pembuatan OP,
     * jadi kalau OP gagal dibuat kelompoknya tetap terbuka.
     */
    public function attachToOrder(RepairGroup $group, ProductionOrder $order): void
    {
        $group->update(['status' => RepairGroup::DI_OP, 'production_order_id' => $order->id]);
    }

    /** Samakan status kelompok dengan status OP-nya. Aman dipanggil untuk OP apa pun. */
    public function syncFromOrder(ProductionOrder $order): void
    {
        $group = RepairGroup::where('production_order_id', $order->id)->first();
        if (!$group) return;

        $status = $order->fresh()->status;

        if ($status === 'cancelled') {
            // OP batal → barangnya kembali dalam kelompok yang sama, bisa dibuatkan OP baru.
            $group->update(['status' => RepairGroup::TERBUKA, 'production_order_id' => null]);
            return;
        }

        $group->update(['status' => $status === 'finalized' ? RepairGroup::SELESAI : RepairGroup::DI_OP]);
    }

    /**
     * Isi kelompok yang siap dijadikan OP: [product_id => qty]. Gagal bila kelompok bukan
     * terbuka, atau stok gudangnya ternyata sudah tak cukup (mis. dipakai OP lain).
     *
     * @return array<int, float>
     */
    public function itemsForOrder(int $groupId): array
    {
        $group = RepairGroup::with('items.product')->lockForUpdate()->findOrFail($groupId);
        $this->assertTerbuka($group);

        if ($group->items->isEmpty()) {
            throw new Exception("Kelompok {$group->number} masih kosong.");
        }

        $tersedia = $this->queue->tersedia($group->id);
        $items = [];
        foreach ($group->items as $it) {
            $qty = (float) $it->qty;
            $ada = (float) ($tersedia[$it->product_id] ?? 0);
            if ($qty > $ada + 1e-6) {
                $label = $it->product ? trim($it->product->sku . ' - ' . $it->product->name) : "produk #{$it->product_id}";
                throw new Exception("Stok {$label} di Gudang Perbaikan tinggal {$this->fmt($ada)}, kelompok meminta {$this->fmt($qty)}. Ubah isi kelompoknya dulu.");
            }
            $items[(int) $it->product_id] = $qty;
        }

        return $items;
    }

    /** @return array<int, float> */
    private function validItems(array $items, ?int $groupId): array
    {
        $clean = [];
        foreach ($items as $pid => $qty) {
            $qty = (float) $qty;
            if ((int) $pid > 0 && $qty > 1e-9) {
                $clean[(int) $pid] = round($qty, 4);
            }
        }

        if (!$clean) {
            throw new Exception('Pilih minimal satu barang dengan qty lebih dari 0.');
        }

        $tersedia = $this->queue->tersedia($groupId);
        foreach ($clean as $pid => $qty) {
            $ada = (float) ($tersedia[$pid] ?? 0);
            if ($qty > $ada + 1e-6) {
                $p = \App\Core\Inventory\Product::find($pid);
                $label = $p ? trim($p->sku . ' - ' . $p->name) : "produk #{$pid}";
                throw new Exception("Qty {$label} ({$this->fmt($qty)}) melebihi yang belum dikelompokkan ({$this->fmt($ada)}).");
            }
        }

        return $clean;
    }

    private function assertTerbuka(RepairGroup $group): void
    {
        if (!$group->isTerbuka()) {
            throw new Exception("Kelompok {$group->number} berstatus {$group->statusLabel()} — hanya kelompok Terbuka yang bisa diubah.");
        }
    }

    private function nextNumber(): string
    {
        $prefix = 'KP/' . now()->format('Y/m') . '/';
        $last = RepairGroup::where('number', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('number')->value('number');
        $seq  = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, ',', '.'), '0'), ',');
    }
}
