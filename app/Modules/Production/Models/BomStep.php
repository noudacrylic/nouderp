<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class BomStep extends Model
{
    /** desain = 'tempel'|'cetak' → langkah Desain tanpa divisi (ProductionOrderStep::DESAIN). */
    protected $fillable = ['bom_id', 'step_number', 'department_id', 'desain', 'name', 'description', 'estimated_hours'];

    protected $casts = [
        'step_number'     => 'integer',
        'estimated_hours' => 'decimal:2',
    ];

    /** Nilai kotak divisi di form ("desain:<jenis>" atau id divisi) — sama dengan langkah OP. */
    public function getPilihanAttribute(): string
    {
        return $this->desain ? 'desain:' . $this->desain : (string) $this->department_id;
    }

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
