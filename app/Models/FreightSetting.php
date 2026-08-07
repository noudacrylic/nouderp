<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreightSetting extends Model
{
    protected $fillable = [
        'gain_account_id',
        'loss_account_id',
        'biteship_saldo_account_id',
        'biteship_fee_account_id',
        'jubelio_saldo_account_id',
        'jubelio_fee_account_id',
    ];

    /**
     * Agregator yang punya saldo prabayar sendiri → butuh akun kas & beban sendiri.
     * Kuncinya = nilai kolom `shipping_provider` di SO/Surat Jalan.
     */
    public const PROVIDERS = [
        'biteship'         => 'Biteship',
        'jubelio_shipment' => 'Jubelio Shipment',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /** Nama kolom akun untuk sebuah provider; provider tak dikenal jatuh ke Biteship. */
    private static function prefix(string $provider): string
    {
        return $provider === 'jubelio_shipment' ? 'jubelio' : 'biteship';
    }

    /** Akun kas deposit provider (dikredit saat resi terbit). */
    public function saldoAccountId(string $provider): ?int
    {
        return $this->{self::prefix($provider) . '_saldo_account_id'} ?: null;
    }

    /** Akun beban layanan/biaya API provider (dipakai rekonsiliasi saldo). */
    public function feeAccountId(string $provider): ?int
    {
        return $this->{self::prefix($provider) . '_fee_account_id'} ?: null;
    }

    public static function providerLabel(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? ucfirst(str_replace('_', ' ', $provider));
    }
}
