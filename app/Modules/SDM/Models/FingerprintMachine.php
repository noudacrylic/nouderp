<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintMachine extends Model
{
    protected $table = 'sdm_fingerprint_machines';

    protected $fillable = [
        'code', 'name', 'serial_number', 'ip_address', 'port',
        'model', 'firmware_version', 'location', 'comm_key',
        'is_active', 'last_seen_at', 'last_sync_at', 'notes',
    ];

    protected $casts = [
        'port'         => 'integer',
        'is_active'    => 'boolean',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(FingerprintLog::class, 'machine_id');
    }

    public function commands()
    {
        return $this->hasMany(FingerprintCommand::class, 'machine_id');
    }

    /**
     * Online jika last_seen_at < 10 menit lalu.
     */
    public function isOnline(): bool
    {
        if (! $this->last_seen_at) return false;
        return $this->last_seen_at->diffInMinutes(now()) < 10;
    }

    /**
     * Mesin yang sudah punya log scan tidak boleh dihapus permanen
     * (jejak attendance harus diabadikan). Pakai archive() saja.
     */
    public function canBeDeleted(): bool
    {
        return $this->logs()->doesntExist();
    }

    public function isArchived(): bool
    {
        return ! $this->is_active;
    }
}
