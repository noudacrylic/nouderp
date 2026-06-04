<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class BomStep extends Model
{
    protected $fillable = ['bom_id', 'step_number', 'department_id', 'name', 'description', 'estimated_hours'];

    protected $casts = [
        'step_number'     => 'integer',
        'estimated_hours' => 'decimal:2',
    ];

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
