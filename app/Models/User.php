<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password',
        'role', 'is_active', 'karyawan_id', 'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    protected ?array $cachedMenuKeys = null;

    public function karyawan()
    {
        return $this->belongsTo(\App\Modules\SDM\Models\Karyawan::class, 'karyawan_id');
    }

    public function menuPermissions()
    {
        return $this->hasMany(UserMenuPermission::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'user';
    }

    public function canManageUsers(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
    }

    /** Cached untuk hindari N queries per page render. */
    public function getAccessibleMenuKeys(): array
    {
        if ($this->cachedMenuKeys !== null) return $this->cachedMenuKeys;
        $this->cachedMenuKeys = $this->menuPermissions()->pluck('menu_key')->all();
        return $this->cachedMenuKeys;
    }

    public function hasMenuPermission(string $key): bool
    {
        return in_array($key, $this->getAccessibleMenuKeys(), true);
    }

    public function clearMenuCache(): void
    {
        $this->cachedMenuKeys = null;
    }
}
