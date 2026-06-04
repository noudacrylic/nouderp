<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SalesQuotationAttachment extends Model
{
    protected $table = 'sales_quotation_attachments';

    protected $fillable = [
        'quotation_id',
        'image_path',
        'title',
        'description',
        'sort_order',
    ];

    public function quotation()
    {
        return $this->belongsTo(SalesQuotation::class, 'quotation_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image_path)) return null;
        // URL host-relative (mis. /storage/...) supaya selalu dimuat dari host yang
        // sedang dipakai (localhost / ngrok), tidak terikat APP_URL. Mencegah thumbnail
        // rusak saat APP_URL diarahkan ke ngrok untuk webhook.
        $full = Storage::disk('public')->url($this->image_path);
        return parse_url($full, PHP_URL_PATH) ?: $full;
    }

    /**
     * Gambar sebagai data URI (base64) supaya tidak bergantung pada APP_URL/host.
     * Mencegah gambar rusak saat APP_URL diarahkan ke ngrok atau saat render PDF
     * (host tidak bisa fetch URL remote). Fallback ke URL biasa bila file tak ada.
     */
    public function getImageDataUriAttribute(): ?string
    {
        if (empty($this->image_path)) return null;

        $disk = Storage::disk('public');
        if (!$disk->exists($this->image_path)) return $this->image_url;

        $mime = $disk->mimeType($this->image_path) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($disk->get($this->image_path));
    }
}
