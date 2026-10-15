<?php

namespace App\Models\Concerns;

use App\Models\DocumentAttachment;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen yang bisa dilampiri foto nota. Model pemakai wajib punya konstanta
 * FOTO_NOTA_JENIS yang terdaftar di FotoNotaService::JENIS.
 */
trait PunyaFotoNota
{
    public static function bootPunyaFotoNota(): void
    {
        // Dokumen draft yang dihapus: fotonya ikut diarsipkan, bukan dibuang.
        static::deleting(function ($doc) {
            $doc->fotoNota()->get()->each->delete();
        });
    }

    public function fotoNota(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class, 'doc_id')
            ->where('doc_type', static::FOTO_NOTA_JENIS);
    }
}
