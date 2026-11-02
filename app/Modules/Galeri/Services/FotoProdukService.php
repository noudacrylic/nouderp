<?php

namespace App\Modules\Galeri\Services;

use App\Modules\Galeri\Models\GaleriFoto;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Foto produk custom: tautan berkas Galeri ↔ pesanan, siapa yang belum difoto, dan pencarian
 * referensi dari chat. "Custom" = OP tipe custom yang pesanannya berisi SKU config galeri.sku_custom:
 * OP tipe custom juga dipakai produk standar buatan per pesanan (mis. Box Charger).
 */
class FotoProdukService
{
    public const KUNCI_SKILL = 'admin.foto-custom';

    public function __construct(private GaleriService $galeri) {}

    /** OP custom selesai yang pesanannya berisi SKU custom dan belum punya foto. */
    public function opBelumDifoto(): Builder
    {
        return DB::table('production_orders as p')
            ->where('p.type', 'custom')
            ->where('p.status', 'finalized')
            ->whereNotNull('p.sales_order_id')
            ->whereExists(fn ($q) => $q->from('sales_order_items as i')
                ->join('products as x', 'x.id', '=', 'i.product_id')
                ->whereColumn('i.sales_order_id', 'p.sales_order_id')
                ->where(fn ($w) => collect(config('galeri.sku_custom'))->each(fn ($pola) => $w->orWhere('x.sku', 'like', $pola))))
            ->whereNotExists(fn ($q) => $q->from('galeri_foto as f')->whereColumn('f.sales_order_id', 'p.sales_order_id'));
    }

    /**
     * Pesanan mana yang perlu pengingat "belum difoto". Hanya saat skill foto Otomatis, dan hanya
     * OP yang selesai sejak skill dinyalakan: pesanan lama tak pernah ditugaskan untuk difoto.
     *
     * @param  int[]  $soIds
     * @return array<int, true>
     */
    public function pengingat(array $soIds): array
    {
        $kv = app(KaryawanVirtualService::class);
        if (!$soIds || $kv->mode(self::KUNCI_SKILL) !== SkillAdmin::OTOMATIS) return [];

        return $this->opBelumDifoto()
            ->whereIn('p.sales_order_id', $soIds)
            ->where('p.finalized_at', '>=', $kv->state(self::KUNCI_SKILL)->mode_diubah_at ?? now())
            ->distinct()->pluck('p.sales_order_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    public function tautkan(string $path, int $soId, ?int $userId = null): GaleriFoto
    {
        return GaleriFoto::firstOrCreate(['path' => $path, 'sales_order_id' => $soId], ['user_id' => $userId]);
    }

    /** Berkas/folder di Galeri dipindah atau diganti namanya: penunjuknya ikut, termasuk isi folder. */
    public function pathBerubah(string $lama, string $baru): void
    {
        $this->baris($lama)->each(function (GaleriFoto $f) use ($lama, $baru) {
            $tujuan = $baru . substr($f->path, strlen($lama));
            GaleriFoto::where('path', $tujuan)->where('sales_order_id', $f->sales_order_id)->exists()
                ? $f->delete()
                : $f->update(['path' => $tujuan]);
        });
    }

    public function pathDihapus(string $path): void
    {
        $this->baris($path)->each->delete();
    }

    /**
     * Referensi foto untuk chat: cari nomor pesanan, SKU, nama barang, atau nama folder/berkas.
     * Berkas yang sudah tak ada (dipindah dari Windows Explorer) dilewati.
     *
     * @return array<int, array{id:int, path:string, nama:string, jenis:string, pesanan:string, barang:string}>
     */
    public function cari(string $kata, int $batas = 30): array
    {
        return GaleriFoto::query()
            ->with('salesOrder.items.product:id,sku,name')
            ->when($kata !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('path', 'like', "%{$kata}%")
                ->orWhereHas('salesOrder', fn ($s) => $s
                    ->where('order_number', 'like', "%{$kata}%")
                    ->orWhere('customer_po_number', 'like', "%{$kata}%")
                    ->orWhereHas('items', fn ($i) => $i
                        ->where('description', 'like', "%{$kata}%")
                        ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$kata}%")->orWhere('name', 'like', "%{$kata}%"))))))
            ->latest('id')
            ->limit($batas)
            ->get()
            ->filter(fn (GaleriFoto $f) => $this->ada($f->path))
            ->map(fn (GaleriFoto $f) => [
                'id'      => $f->id,
                'path'    => $f->path,
                'nama'    => basename($f->path),
                'jenis'   => $this->galeri->jenis(basename($f->path)),
                'pesanan' => (string) $f->salesOrder?->order_number,
                'barang'  => (string) $f->salesOrder?->items
                    ->map(fn ($i) => $i->description ?: $i->product?->name)->filter()->unique()->take(3)->implode(', '),
            ])
            ->values()->all();
    }

    private function baris(string $path): Collection
    {
        return GaleriFoto::where('path', $path)
            ->orWhere('path', 'like', addcslashes($path, '\\%_') . '/%')
            ->get();
    }

    private function ada(string $path): bool
    {
        try {
            return is_file($this->galeri->abs($this->galeri->normalisasi($path)));
        } catch (GaleriException) {
            return false;
        }
    }
}
