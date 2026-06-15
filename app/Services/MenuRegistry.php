<?php

namespace App\Services;

use App\Modules\Production\Models\Department;
use Illuminate\Support\Str;

class MenuRegistry
{
    private ?array $flatCache = null;
    private ?array $deptCache = null;

    public function tree(): array
    {
        return config('menu_permissions', []);
    }

    /** Flat map: menu_key => config (semua leaf node, dengan path lengkap). */
    public function flat(): array
    {
        if ($this->flatCache !== null) return $this->flatCache;

        $out = [];
        foreach ($this->tree() as $key => $cfg) {
            if (!empty($cfg['children'])) {
                foreach ($cfg['children'] as $childKey => $childCfg) {
                    $out[$childKey] = $childCfg + ['_parent' => $key];
                }
            } else {
                $out[$key] = $cfg;
            }
        }
        $this->flatCache = $out;
        return $out;
    }

    /**
     * Resolve route name → menu_key. Return null jika tidak ketemu.
     * Untuk route `production.process.*`, return menu_key BASE (`production.process`) —
     * caller harus combine dengan ?department_id untuk dapat key specific.
     */
    public function resolveMenuKey(string $routeName): ?string
    {
        foreach ($this->flat() as $key => $cfg) {
            foreach ($cfg['route_patterns'] ?? [] as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Root-level keys. */
    public function groupKeys(): array
    {
        return array_keys($this->tree());
    }

    /**
     * Leaf menu_key yang berada di bawah satu modul/group (mis. 'purchasing' →
     * ['purchasing.suppliers','purchasing.orders',...]). Dipakai untuk mengizinkan
     * route bantu (api/ajax) milik modul saat user punya akses ke salah satu menunya.
     */
    public function moduleKeys(string $module): array
    {
        $out = [];
        foreach ($this->flat() as $key => $cfg) {
            if ($key === $module || str_starts_with($key, $module . '.')) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Daftar divisi produksi (cached). Dipakai untuk dynamic children di Proses Produksi.
     */
    public function productionDepartments(): \Illuminate\Support\Collection
    {
        if ($this->deptCache !== null) {
            return collect($this->deptCache);
        }
        try {
            $this->deptCache = Department::where('type', 'produksi')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all();
        } catch (\Throwable $e) {
            $this->deptCache = [];
        }
        return collect($this->deptCache);
    }

    /**
     * Tree untuk UI permission centang — dengan departments di-inject sebagai children
     * di bawah `production.process`. Dipakai oleh halaman Settings → User & Akses.
     */
    public function treeForUI(): array
    {
        $tree = $this->tree();
        if (!isset($tree['production']['children']['production.process'])) {
            return $tree;
        }

        $depts = $this->productionDepartments();
        if ($depts->isEmpty()) return $tree;

        $deptChildren = [];
        foreach ($depts as $dept) {
            $deptChildren["production.process.{$dept->id}"] = [
                'label' => $dept->name,
            ];
        }

        $tree['production']['children']['production.process']['children'] = $deptChildren;
        return $tree;
    }

    /**
     * Flat keys termasuk dept-specific keys (`production.process.{id}`). Dipakai untuk
     * sanitasi permission yang di-submit dari form (sync ke user_menu_permissions).
     */
    public function flatForValidation(): array
    {
        $keys = array_keys($this->flat());
        foreach ($this->productionDepartments() as $dept) {
            $keys[] = "production.process.{$dept->id}";
        }
        return $keys;
    }

    /**
     * Cek apakah user (role=user) punya akses ke route `production.process.*`
     * dengan parameter ?department_id=X. Return true jika:
     *   - User punya umbrella key `production.process`, ATAU
     *   - Department ID hadir & user punya `production.process.{id}`, ATAU
     *   - Tidak ada department_id & user punya minimal 1 dept-specific.
     */
    public function userCanAccessProcessRoute(?int $departmentId, callable $userHasKey): bool
    {
        if ($userHasKey('production.process')) return true;
        if ($departmentId !== null) {
            return $userHasKey("production.process.{$departmentId}");
        }
        // Listing tanpa dept tertentu — allow kalau ada minimal 1 dept specific
        foreach ($this->productionDepartments() as $dept) {
            if ($userHasKey("production.process.{$dept->id}")) return true;
        }
        return false;
    }
}
