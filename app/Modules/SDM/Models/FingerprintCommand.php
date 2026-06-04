<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintCommand extends Model
{
    protected $table = 'sdm_fingerprint_commands';

    protected $fillable = [
        'machine_id', 'command', 'description',
        'status', 'response', 'sent_at', 'executed_at',
    ];

    protected $casts = [
        'sent_at'     => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(FingerprintMachine::class, 'machine_id');
    }
}
