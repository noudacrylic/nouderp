<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Models\Concerns\SafeEncryptedReads;
use Illuminate\Database\Eloquent\Model;

/**
 * Setting singleton Shopee Open Platform (1 baris, id=1) — pola seperti MidtransSetting.
 * Kunci yang BERLAKU dibaca lewat partnerId()/partnerKey() untuk lingkungan aktif;
 * .env (SHOPEE_PARTNER_ID/KEY) hanya cadangan bila kolomnya belum pernah diisi.
 */
class ShopeeSetting extends Model
{
    use SafeEncryptedReads;

    public const ENVS = ['test', 'live'];

    protected $fillable = ['env', 'test_partner_id', 'test_partner_key', 'live_partner_id', 'live_partner_key', 'live_key_expires_at', 'chat_owner_user_id'];

    protected $hidden = ['test_partner_key', 'live_partner_key'];

    protected $casts = [
        'test_partner_id'  => 'integer',
        'test_partner_key' => 'encrypted',
        'live_partner_id'  => 'integer',
        'live_partner_key' => 'encrypted',
        'live_key_expires_at' => 'date',
        'chat_owner_user_id'  => 'integer',
    ];

    public static function singleton(): self
    {
        // id dipaksa 1 (bukan firstOrCreate): 'id' tidak fillable, jadi baris baru bisa
        // mendapat id lain dan tiap pemanggilan membuat baris baru lagi.
        return self::find(1) ?? tap(new self(), fn (self $s) => $s->forceFill([
            'id'  => 1,
            'env' => config('services.shopee.env') === 'live' ? 'live' : 'test',
        ])->save());
    }

    public function envAktif(): string
    {
        return $this->env === 'live' ? 'live' : 'test';
    }

    public function partnerId(?string $env = null): int
    {
        $env ??= $this->envAktif();

        return (int) ($this->{$env . '_partner_id'} ?: $this->dariEnv($env, 'partner_id'));
    }

    public function partnerKey(?string $env = null): string
    {
        $env ??= $this->envAktif();

        return (string) ($this->safeAttr($env . '_partner_key') ?: $this->dariEnv($env, 'partner_key'));
    }

    public function siap(?string $env = null): bool
    {
        return $this->partnerId($env) > 0 && $this->partnerKey($env) !== '';
    }

    /** Sisa hari sampai Live Partner Key kedaluwarsa (negatif = sudah lewat); null bila tanggal belum diisi. */
    public function sisaHariKunciLive(): ?int
    {
        return $this->live_key_expires_at
            ? (int) now()->startOfDay()->diffInDays($this->live_key_expires_at->copy()->startOfDay(), false)
            : null;
    }

    /** Kunci tersimpan di DB (bukan cadangan .env)? Untuk keterangan di halaman pengaturan. */
    public function tersimpan(string $env): bool
    {
        return (bool) $this->{$env . '_partner_id'} && (bool) $this->safeAttr($env . '_partner_key');
    }

    /** Cadangan .env hanya berlaku untuk lingkungan yang sama dengan SHOPEE_ENV. */
    private function dariEnv(string $env, string $kunci): mixed
    {
        $envFile = config('services.shopee.env') === 'live' ? 'live' : 'test';

        return $env === $envFile ? config('services.shopee.' . $kunci) : null;
    }
}
