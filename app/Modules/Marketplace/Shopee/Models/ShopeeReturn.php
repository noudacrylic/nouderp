<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Modules\Marketplace\Shopee\Services\ShopeeReturLamaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu pengajuan retur Shopee (salinan get_return_list, lihat ShopeeReturService::tarik).
 */
class ShopeeReturn extends Model
{
    /** Penjual sudah menyengketakan / sedang dinilai Shopee. */
    public const STATUS_SENGKETA = ['JUDGING', 'SELLER_DISPUTE'];

    /** Alasan pengajuan retur Shopee (`reason`) dalam bahasa admin. */
    public const ALASAN = [
        'BROKEN_PRODUCTS'       => 'Barang pecah / rusak',
        'PHYSICAL_DMG'          => 'Barang rusak fisik',
        'ITEM_DAMAGED'          => 'Barang rusak',
        'SLIGHT_SCRATCH_DENTS'  => 'Lecet / penyok',
        'FUNCTIONAL_DMG'        => 'Tidak berfungsi',
        'WRONG_ITEM'            => 'Salah kirim barang',
        'ITEM_WRONGDAMAGED'     => 'Salah kirim / rusak',
        'DIFFERENT_DESCRIPTION' => 'Tidak sesuai deskripsi',
        'ITEM_MISSING'          => 'Barang kurang / tidak lengkap',
        'NOT_RECEIPT'           => 'Barang tidak diterima',
        'CHANGE_MIND'           => 'Berubah pikiran',
        'EXPECTATION_FAILED'    => 'Tidak sesuai harapan',
        'ITEM_FAKE'             => 'Barang palsu',
        'MUTUAL_AGREE'          => 'Kesepakatan dengan penjual',
        'OTHER'                 => 'Lainnya',
    ];

    protected $fillable = [
        'env', 'shop_id', 'return_sn', 'order_sn', 'status', 'reason', 'text_reason', 'refund_amount',
        'needs_logistics', 'negotiation_status', 'seller_proof_status', 'seller_compensation_status',
        'pernah_disengketakan', 'seller_due_at', 'dibuat_shopee_at', 'diperbarui_shopee_at', 'raw', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'              => 'integer',
        'refund_amount'        => 'decimal:2',
        'needs_logistics'      => 'boolean',
        'pernah_disengketakan' => 'boolean',
        'seller_due_at'        => 'datetime',
        'dibuat_shopee_at'     => 'datetime',
        'diperbarui_shopee_at' => 'datetime',
        'raw'                  => 'array',
        'disinkron_at'         => 'datetime',
    ];

    public function final(): bool
    {
        return in_array($this->status, ShopeeReturLamaService::STATUS_RETUR_FINAL, true);
    }

    /** Sedang dibanding: status sengketa, atau bukti penjual sudah/masih diminta. */
    public function disengketakan(): bool
    {
        return in_array($this->status, self::STATUS_SENGKETA, true)
            || in_array($this->seller_proof_status, ['PENDING', 'UPLOADED'], true);
    }

    /**
     * Batas penjual menanggapi retur yang masih berjalan. Shopee mengisi
     * `return_seller_due_date` setelah barang dikirim balik; selama pengajuan awal /
     * tawaran pembeli belum dijawab nilainya 0, dan batasnya `due_date` (3 hari sejak
     * pengajuan). Retur final tak punya tenggat.
     */
    public function tenggatPenjual(): ?Carbon
    {
        if ($this->final()) {
            return null;
        }
        if ($this->seller_due_at) {
            return $this->seller_due_at;
        }
        $due = (int) ($this->raw['due_date'] ?? 0);

        return $due > 0 ? Carbon::createFromTimestamp($due, config('app.timezone')) : null;
    }

    /**
     * Ringkasan semua pengajuan retur satu pesanan untuk daftar retur ERP.
     * Pola fase sama dengan ReturSkill::fase(): banding → periksa → selesai.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $list
     * @return array{label: string, warna: string, tenggat: ?Carbon, catatan: ?string}
     */
    public static function ringkasan($list): array
    {
        $berjalan = $list->reject->final();
        $tenggat  = $berjalan->map->tenggatPenjual()->filter()->min();

        if ($berjalan->contains->disengketakan()) {
            return ['label' => 'Sedang banding', 'warna' => 'purple', 'tenggat' => $tenggat, 'catatan' => null, 'kirim_balik' => self::kirimBalikDari($list)];
        }
        if ($berjalan->isNotEmpty()) {
            $tawaran = $berjalan->contains('negotiation_status', 'PENDING_RESPOND');
            return [
                'label'   => $tawaran ? 'Jawab tawaran pembeli' : 'Perlu keputusan: terima / banding',
                'warna'   => 'orange',
                'tenggat' => $tenggat,
                'catatan' => $berjalan->contains('needs_logistics', true) ? 'barang dikirim balik' : null,
                'kirim_balik' => self::kirimBalikDari($list),
            ];
        }
        if ($list->every(fn ($x) => $x->status === 'CANCELLED')) {
            return ['label' => 'Dibatalkan pembeli', 'warna' => 'gray', 'tenggat' => null, 'catatan' => null, 'kirim_balik' => []];
        }

        return [
            'label'   => $list->contains('pernah_disengketakan', true) ? 'Selesai (pernah banding)' : 'Selesai, tanpa banding',
            'warna'   => 'green',
            'tenggat' => null,
            'catatan' => $list->contains('seller_compensation_status', 'PENDING_REQUEST') ? 'kompensasi bisa diajukan' : null,
        ] + ['kirim_balik' => self::kirimBalikDari($list)];
    }

    /**
     * Paket yang dikirim balik pembeli (hanya retur ber-logistik yang tidak dibatalkan), dari
     * salinan get_return_list: `tracking_number` = resi pengembalian (kosong selama pembeli
     * belum kirim), `is_arrived_at_warehouse`, `return_ship_due_date` = batas pembeli kirim.
     *
     * @return array{return_sn: string, resi: ?string, sampai: bool, batas: ?Carbon}|null
     */
    public function kirimBalik(): ?array
    {
        if (!$this->needs_logistics || $this->status === 'CANCELLED') {
            return null;
        }
        $raw = $this->raw ?? [];
        $batas = (int) ($raw['return_ship_due_date'] ?? 0);

        return [
            'return_sn' => (string) $this->return_sn,
            'resi'      => trim((string) ($raw['tracking_number'] ?? '')) ?: null,
            'sampai'    => (bool) ($raw['is_arrived_at_warehouse'] ?? false),
            'batas'     => $batas > 0 ? Carbon::createFromTimestamp($batas, config('app.timezone')) : null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, self>  $list
     * @return array<int, array{return_sn: string, resi: ?string, sampai: bool, batas: ?Carbon}>
     */
    public static function kirimBalikDari($list): array
    {
        return collect($list)->map->kirimBalik()->filter()->values()->all();
    }

    /** Paket kirim balik per order_sn (tanpa saring env, sama dengan ReturSkill — order_sn sandbox tak bertabrakan) — untuk daftar/kartu yang memuat banyak retur. */
    public static function kirimBalikPerPesanan(array $orderSn): array
    {
        $orderSn = array_values(array_unique(array_filter($orderSn)));
        if (!$orderSn) return [];

        return self::whereIn('order_sn', $orderSn)->orderBy('dibuat_shopee_at')->get()
            ->groupBy('order_sn')->map(fn ($l) => self::kirimBalikDari($l))->filter()->all();
    }

    public function alasanLabel(): string
    {
        return self::ALASAN[$this->reason] ?? ($this->reason ?: 'tanpa alasan');
    }

    /** Bentuk yang dipakai ShopeeReturLamaService (sama dengan baris get_return_list). */
    public function sebagaiApi(): array
    {
        return [
            'return_sn' => $this->return_sn, 'status' => $this->status, 'reason' => $this->reason,
            'refund_amount' => (float) $this->refund_amount, 'needs_logistics' => $this->needs_logistics,
        ];
    }
}
