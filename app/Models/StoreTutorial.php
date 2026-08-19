<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoreTutorial extends Model
{
    protected $fillable = [
        'code',
        'slug',
        'title',
        'youtube_id',
        'description',
        'content',
        'status',
        'sort_order',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'scan_count' => 'integer',
        'view_count' => 'integer',
    ];

    public function products()
    {
        return $this->belongsToMany(StoreProduct::class, 'store_tutorial_product')
            ->withPivot('sort_order')
            ->orderBy('store_tutorial_product.sort_order')
            ->orderBy('store_products.name');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function isLive(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Terima apa pun yang ditempel admin — URL panjang, URL pendek, URL embed,
     * atau ID telanjang — kembalikan ID videonya saja.
     *
     * Admin menempel dari bilah alamat YouTube, dan bentuknya berbeda-beda
     * tergantung dari mana ia menyalin (tombol Bagikan memberi youtu.be, bilah
     * alamat memberi watch?v=, aplikasi HP kadang menambahkan ?si=...). Menolak
     * salah satu bentuknya hanya membuat admin menebak-nebak.
     */
    public static function extractYoutubeId(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') return null;

        // Sudah berupa ID telanjang (11 karakter, tanpa tanda garis miring).
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $input)) return $input;

        $patterns = [
            '~youtu\.be/([A-Za-z0-9_-]{11})~',
            '~[?&]v=([A-Za-z0-9_-]{11})~',
            '~youtube\.com/embed/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/shorts/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/live/([A-Za-z0-9_-]{11})~',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $input, $m)) return $m[1];
        }

        return null;
    }

    /** Alamat pendek yang TERCETAK di stiker. Selalu huruf kecil untuk mata manusia. */
    public function shortUrl(): string
    {
        return rtrim((string) config('store.storefront_url'), '/') . '/t/' . strtolower($this->code);
    }

    /**
     * Yang disandikan ke dalam QR: HURUF BESAR.
     *
     * QR punya mode alfanumerik khusus (huruf besar, angka, dan tanda baca
     * termasuk ":" dan "/") yang jauh lebih padat daripada mode teks biasa.
     * "HTTPS://NOUDAKRILIK.COM/T/TB1" muat di QR versi 3 (29x29 kotak),
     * sedangkan versi huruf kecilnya butuh versi 4 (33x33). Pada stiker
     * berukuran sama, tiap kotak jadi ~11% lebih besar — dan itulah yang
     * menentukan berhasil-tidaknya scan di akrilik yang mengkilap.
     *
     * Nama domain memang tak peduli besar-kecil huruf; path-nya yang dibuat
     * menerima keduanya (lihat resolusi kode di storefront).
     */
    public function qrPayload(): string
    {
        return strtoupper($this->shortUrl());
    }

    public function canonicalUrl(): string
    {
        return rtrim((string) config('store.storefront_url'), '/') . '/tutorial/' . $this->slug;
    }

    /** Gambar sampul video, dipakai di daftar & sebagai pratinjau sebelum iframe dimuat. */
    public function thumbnailUrl(): ?string
    {
        return $this->youtube_id
            ? "https://i.ytimg.com/vi/{$this->youtube_id}/hqdefault.jpg"
            : null;
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'tutorial';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
