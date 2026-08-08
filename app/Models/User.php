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

    /**
     * Akun yang boleh dipilih di dropdown penugasan ERP (Task, Jadwal, Otomasi).
     *
     * Akun PWA karyawan (role 'karyawan') SENGAJA dikecualikan: aksesnya hanya /me/*,
     * mereka tidak pernah membuka halaman Task — task yang ditugaskan ke sana tidak
     * akan pernah terlihat oleh siapa pun.
     */
    public function scopeAssignable($query)
    {
        return $query->where('is_active', true)->where('role', '!=', 'karyawan');
    }

    /**
     * Aturan validasi pasangan scopeAssignable() untuk kolom penerima tugas.
     * Sengaja TIDAK ikut menyaring is_active supaya task lama yang penugasnya sudah
     * dinonaktifkan masih bisa disunting; yang dijaga di sini khusus akun PWA.
     */
    public static function assignableExistsRule(): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('users', 'id')
            ->where(fn ($q) => $q->where('role', '!=', 'karyawan'));
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
