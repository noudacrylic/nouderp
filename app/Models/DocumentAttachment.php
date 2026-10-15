<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Foto nota / bukti yang menempel di dokumen. Lihat FotoNotaService.
 */
class DocumentAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'doc_type', 'doc_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes', 'uploaded_by', 'deleted_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
