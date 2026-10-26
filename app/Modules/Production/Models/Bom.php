<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class Bom extends Model
{
    protected $fillable = [
        'bom_number', 'name', 'description', 'cycles_description', 'typical_cycles', 'score', 'auto_production', 'urutan_pakai',
    ];

    protected $casts = [
        'score'           => 'float',
        'typical_cycles'  => 'integer',
        'auto_production' => 'boolean',
        'urutan_pakai'    => 'integer',
    ];

    public function materials()
    {
        return $this->hasMany(BomMaterial::class);
    }

    public function outputs()
    {
        return $this->hasMany(BomOutput::class);
    }

    public function mainOutput()
    {
        return $this->hasMany(BomOutput::class)->where('output_type', 'main');
    }

    public function steps()
    {
        return $this->hasMany(BomStep::class)->orderBy('step_number');
    }

    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class);
    }

}
