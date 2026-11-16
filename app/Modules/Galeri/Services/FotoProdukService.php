<?php

namespace App\Modules\Galeri\Services;

use App\Modules\Galeri\Models\GaleriFoto;
use App\Modules\Galeri\Models\GaleriKarya;
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

    // ───────────── Info foto pesanan (karya) ─────────────

    /** Pesanan berisi barang yang wajib difoto (config galeri.wajib_foto)? */
    public function wajibFoto(int $soId): bool
    {
        $pola = config('galeri.wajib_foto', []);
        if (!$pola) return false;

        return DB::table('sales_order_items as i')->join('products as x', 'x.id', '=', 'i.product_id')
            ->where('i.sales_order_id', $soId)
            ->where(fn ($w) => collect($pola)->each(fn ($p) => $w->orWhere('x.sku', 'like', $p)))
            ->exists();
    }

    /**
     * Apa yang masih kurang sebelum kartu foto boleh ditutup; null = lengkap. Lengkap = info foto
     * terisi (lini; nama instansi bila layak Galeri) dan minimal satu foto tertaut.
     */
    public function kekuranganFoto(int $soId): ?string
    {
        $karya = GaleriKarya::where('sales_order_id', $soId)->first();
        if (!$karya) return 'Isi info foto dulu (tombol 📷 Foto produk).';
        if ($karya->layak_galeri && blank($karya->nama_instansi)) return 'Nama instansi belum diisi.';

        $foto = GaleriFoto::where('sales_order_id', $soId)->pluck('path')
            ->contains(fn ($p) => $this->galeri->jenis(basename($p)) === 'gambar' && $this->ada($p));

        return $foto ? null : 'Belum ada foto yang terunggah untuk pesanan ini.';
    }

    /** Lini yang ditawarkan untuk pesanan ini (dari awalan SKU barangnya). */
    public function liniUsulan(int $soId): ?string
    {
        $skus = DB::table('sales_order_items as i')->join('products as x', 'x.id', '=', 'i.product_id')
            ->where('i.sales_order_id', $soId)->pluck('x.sku');
        foreach ($skus as $sku) {
            foreach (config('galeri.lini_sku', []) as $awal => $lini) {
                if (stripos((string) $sku, $awal) === 0) return $lini;
            }
        }
        return null;
    }

    /**
     * Simpan info foto + pastikan folder `<lini>/porto/<SO instansi>` ada. Lini diganti setelah ada
     * foto → folder pesanan ikut dipindah (tautan foto ikut lewat pathBerubah).
     */
    public function simpanKarya(int $soId, array $data, ?int $userId = null): GaleriKarya
    {
        $nomor = (string) DB::table('sales_orders')->where('id', $soId)->value('order_number');
        $lini = $this->galeri->namaAman((string) $data['lini']);
        $karya = GaleriKarya::firstOrNew(['sales_order_id' => $soId]);

        $porto = $lini . '/' . config('galeri.folder_porto', 'porto');
        if (!$karya->exists) {
            $nama = trim(str_replace('/', '-', $nomor) . ' ' . ($data['nama_instansi'] ?? ''));
            $karya->folder = $this->galeri->pastikanFolder($porto . '/' . $this->galeri->namaAman($nama));
        } elseif ($karya->lini !== $lini) {
            $tujuan = $this->galeri->pastikanFolder($porto);
            $lama = $karya->folder;
            $karya->folder = is_dir($this->galeri->abs($lama))
                ? $this->galeri->pindah($lama, $tujuan)
                : $this->galeri->pastikanFolder($tujuan . '/' . basename($lama));
            $this->pathBerubah($lama, $karya->folder);
            if ($karya->foto_utama && str_starts_with($karya->foto_utama, $lama . '/')) {
                $karya->foto_utama = $karya->folder . substr($karya->foto_utama, strlen($lama));
            }
        }

        $karya->fill([
            'lini'          => $lini,
            'layak_galeri'  => (bool) ($data['layak_galeri'] ?? false),
            'nama_instansi' => filled($data['nama_instansi'] ?? null) ? trim($data['nama_instansi']) : null,
            'jenis_produk'  => filled($data['jenis_produk'] ?? null) ? trim($data['jenis_produk']) : null,
            'catatan'       => filled($data['catatan'] ?? null) ? trim($data['catatan']) : null,
        ]);
        $karya->user_id ??= $userId;
        $karya->save();

        return $karya;
    }

    /** Kartu ditutup tanpa foto — alasannya tercatat (bahan penilaian & supaya tak dicari lagi). */
    public function tandaiTanpaFoto(int $soId, string $alasan, ?int $userId = null): void
    {
        $karya = GaleriKarya::firstOrNew(['sales_order_id' => $soId]);
        if (!$karya->exists) {
            $karya->fill(['lini' => '-', 'folder' => '', 'user_id' => $userId]);
        }
        $karya->tanpa_foto_alasan = mb_substr($alasan, 0, 150);
        $karya->save();
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

        // Info foto: folder pesanan & foto utama ikut.
        $pola = addcslashes($lama, '\%_') . '/%';
        GaleriKarya::where('folder', $lama)->orWhere('folder', 'like', $pola)->get()
            ->each(fn (GaleriKarya $k) => $k->update(['folder' => $baru . substr($k->folder, strlen($lama))]));
        GaleriKarya::where('foto_utama', $lama)->orWhere('foto_utama', 'like', $pola)->get()
            ->each(fn (GaleriKarya $k) => $k->update(['foto_utama' => $baru . substr($k->foto_utama, strlen($lama))]));
    }

    public function pathDihapus(string $path): void
    {
        $this->baris($path)->each->delete();
        GaleriKarya::where('foto_utama', $path)->orWhere('foto_utama', 'like', addcslashes($path, '\%_') . '/%')
            ->update(['foto_utama' => null]);
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
