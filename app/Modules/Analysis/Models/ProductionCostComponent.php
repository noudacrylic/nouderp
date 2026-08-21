<?php

namespace App\Modules\Analysis\Models;

use App\Core\Accounting\Account;
use App\Modules\Production\Models\Department;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris penyusun biaya tetap. Sumbernya bisa akun buku besar (nilainya ditarik
 * otomatis, rata-rata per bulan) atau angka manual (untuk biaya yang belum punya akun).
 */
class ProductionCostComponent extends Model
{
    protected $table = 'production_cost_components';

    protected $fillable = [
        'group_key', 'department_id', 'name', 'source',
        'account_id', 'percentage', 'amount_monthly', 'notes', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'percentage'     => 'decimal:2',
        'amount_monthly' => 'decimal:2',
        'sort_order'     => 'integer',
        'is_active'      => 'boolean',
    ];

    /** Grup yang bukan milik satu divisi — totalnya dibagi ke divisi produksi sesuai porsi jam. */
    public const POOL_GROUPS = ['non_produksi', 'packing', 'overhead_produksi'];

    public const GROUP_LABELS = [
        'non_produksi'      => 'Non Produksi',
        'packing'           => 'Packing',
        'overhead_produksi' => 'Overhead Produksi',
        'divisi'            => 'Divisi Produksi',
    ];

    /**
     * Grup yang tarifnya diukur per TRANSAKSI, bukan per jam.
     *
     * Kerja packing tidak mengikuti lamanya pabrik buka — yang menentukan adalah
     * berapa paket yang keluar. Membaginya dengan jam operasional membuat biayanya
     * tampak tetap padahal ikut naik-turun mengikuti jumlah pengiriman.
     */
    public const TRANSACTION_GROUPS = ['packing'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
