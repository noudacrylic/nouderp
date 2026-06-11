<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BusinessProfile extends Model
{
    protected $fillable = [
        'name',
        'address',
        'city',
        'province',
        'postal_code',
        'biteship_area_id',
        'kiriminaja_area_id',
        'phone',
        'whatsapp',
        'email',
        'logo_path',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'signature_path',
        'signer_name',
        'signer_title',
        'quotation_opening_text',
        'quotation_payment_terms',
        'payment_instructions',
    ];

    /**
     * Singleton row. Auto-create dgn default text kalau belum ada.
     */
    public static function instance(): self
    {
        $row = static::first();
        if (!$row) {
            $row = static::create([
                'quotation_opening_text' => 'Dengan Hormat, Kami {name} bermaksud menawarkan barang kepada bapak/ibu. Kami memberikan penawaran harga yang sangat menarik untuk Bapak/Ibu. Berikut Adalah harga yang kami tawarkan :',
                'quotation_payment_terms' => 'Pembayaran dilakukan dengan DP (uang muka) minimal 50% di muka, dan pelunasan sebelum barang dikirim. Detail metode/nomor pembayaran akan kami informasikan setelah pesanan dikonfirmasi.',
                'payment_instructions' => 'Silakan lakukan pembayaran dengan scan QR di samping menggunakan kamera HP, lalu pilih metode pembayaran (QRIS / transfer / e-wallet). Jika mengalami kesulitan, silakan hubungi admin kami.',
            ]);
        }
        return $row;
    }

    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class, 'business_profile_id')
            ->orderBy('sort_order');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getSignatureUrlAttribute(): ?string
    {
        return $this->signature_path ? Storage::disk('public')->url($this->signature_path) : null;
    }

    /**
     * Logo sebagai data URI base64 (embed langsung di HTML).
     * Dipakai di halaman print/PDF agar logo tetap muncul tanpa bergantung pada
     * APP_URL/host (mis. saat APP_URL diarahkan ke ngrok untuk webhook, atau saat
     * render PDF yang tidak bisa fetch URL remote). Fallback ke URL biasa.
     */
    public function getLogoDataUriAttribute(): ?string
    {
        return $this->fileToDataUri($this->logo_path) ?? $this->logo_url;
    }

    public function getSignatureDataUriAttribute(): ?string
    {
        return $this->fileToDataUri($this->signature_path) ?? $this->signature_url;
    }

    private function fileToDataUri(?string $path): ?string
    {
        if (!$path) return null;
        $disk = Storage::disk('public');
        if (!$disk->exists($path)) return null;

        $mime = $disk->mimeType($path) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($disk->get($path));
    }

    /**
     * Render opening text dgn placeholder {name} diganti nama bisnis.
     */
    public function renderedOpeningText(): string
    {
        $text = $this->quotation_opening_text ?? '';
        return str_replace('{name}', $this->name ?? '', $text);
    }
}
