<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class KomponenLaporanItem extends Model
{
    protected $table = 'production_komponen_laporan_items';

    protected $guarded = [];

    public function laporan()
    {
        return $this->belongsTo(KomponenLaporan::class, 'laporan_id');
    }

    public function fotoUrl(): ?string
    {
        return $this->foto_path ? asset('storage/' . $this->foto_path) : null;
    }
}
