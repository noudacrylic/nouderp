<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentExecutor extends Model
{
    protected $table = 'production_department_executors';

    protected $fillable = [
        'department_id', 'karyawan_id', 'parent_executor_id',
        'name', 'role', 'mesin_1', 'mesin_2', 'mesin_3', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function karyawan()
    {
        return $this->belongsTo(\App\Modules\SDM\Models\Karyawan::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_executor_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_executor_id');
    }

    /**
     * Eksekutor yang boleh dipilih saat memulai langkah = yang benar-benar MENGERJAKAN.
     *
     * Operator yang menaungi mesin (punya anak) tidak ikut: di CNC yang bekerja mesinnya,
     * operator hanya menekan play lalu ditinggal. Kalau operator ikut dicentang, jam yang
     * sama tercatat dua kali dan kapasitasnya jadi tidak bisa dibaca. Barisnya tetap ada
     * karena hierarki ini yang dipakai auto-pause: scan pulang operator ikut menghentikan
     * mesin-mesin di bawahnya.
     */
    public function scopeSelectable($query)
    {
        return $query->where('is_active', true)->whereDoesntHave('children');
    }

    /** Operator penaung — ada di pohon, tapi tidak pernah jadi pelaku langkah. */
    public function isSupervisor(): bool
    {
        return $this->children()->exists();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->karyawan?->name ?? $this->name;
    }

    /**
     * Walk parent chain to find the topmost ancestor (root operator).
     * Loop-safe via depth limit.
     */
    public function topAncestor(): self
    {
        $current = $this;
        $depth = 0;
        while ($current->parent && $depth < 10) {
            $current = $current->parent;
            $depth++;
        }
        return $current;
    }

    /**
     * Karyawan ID efektif: dari self, fallback ke topAncestor (untuk mesin tanpa karyawan).
     */
    public function effectiveKaryawanId(): ?int
    {
        if ($this->karyawan_id) return (int) $this->karyawan_id;
        $top = $this->topAncestor();
        return $top->karyawan_id ? (int) $top->karyawan_id : null;
    }

    /**
     * Semua ID descendant (children, grandchildren, dst). Loop-safe.
     */
    public function descendantIds(): array
    {
        $ids = [];
        $queue = [$this->id];
        $depth = 0;
        while (!empty($queue) && $depth < 10) {
            $children = self::whereIn('parent_executor_id', $queue)->pluck('id')->toArray();
            $queue = array_diff($children, $ids);
            $ids = array_merge($ids, $queue);
            $depth++;
        }
        return $ids;
    }
}
