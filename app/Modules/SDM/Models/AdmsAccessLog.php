<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class AdmsAccessLog extends Model
{
    protected $table = 'sdm_adms_access_log';

    protected $fillable = [
        'occurred_at', 'method', 'path', 'query_string', 'serial_number',
        'client_ip', 'body_sample', 'response_code', 'response_sample', 'machine_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(FingerprintMachine::class, 'machine_id');
    }

    /**
     * Auto-prune: keep only the most recent N rows.
     */
    public static function prune(int $keep = 500): void
    {
        $threshold = static::orderByDesc('id')->skip($keep)->take(1)->value('id');
        if ($threshold) {
            static::where('id', '<=', $threshold)->delete();
        }
    }
}
