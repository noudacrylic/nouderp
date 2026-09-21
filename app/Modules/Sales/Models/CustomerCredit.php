<?php

namespace App\Modules\Sales\Models;

use App\Core\Accounting\Account;
use App\Models\Customer;
use App\Models\CustomerOverpayment;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen pemberian / pengurangan saldo kredit pelanggan secara manual.
 * Saldonya sendiri hidup di kolam `customer_overpayments` (lihat CustomerCreditService).
 */
class CustomerCredit extends Model
{
    public const DIRECTIONS = [
        'tambah' => 'Tambah Saldo',
        'kurang' => 'Kurangi Saldo',
    ];

    protected $fillable = [
        'credit_number', 'customer_id', 'credit_date', 'direction', 'amount',
        'counter_account_id', 'reason', 'status', 'journal_id', 'created_by', 'voided_at',
    ];

    protected $casts = [
        'credit_date' => 'date',
        'amount'      => 'decimal:2',
        'voided_at'   => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function counterAccount()
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    /** Dampak dokumen ini terhadap saldo pelanggan: positif menambah, negatif mengurangi. */
    public function signedAmount(): float
    {
        return round((float) $this->amount * ($this->direction === 'kurang' ? -1 : 1), 2);
    }

    public function directionLabel(): string
    {
        return self::DIRECTIONS[$this->direction] ?? $this->direction;
    }

    /**
     * Boleh di-void?
     *
     * Bukan sekadar "masih posted". Saldo yang diberikan bisa SUDAH TERPAKAI membayar faktur,
     * dan menariknya kembali akan membuat saldo pelanggan minus — artinya ERP mengaku berutang
     * saldo yang tak pernah ada. Jadi void hanya boleh bila saldo yang tersisa masih cukup
     * untuk menanggung pembatalannya.
     */
    public function canBeVoided(): bool
    {
        if ($this->status !== 'posted') {
            return false;
        }

        $saldo = (float) CustomerOverpayment::where('customer_id', $this->customer_id)->sum('amount');

        return round($saldo - $this->signedAmount(), 2) >= -0.005;
    }

    /** Alasan tak bisa di-void, untuk ditampilkan apa adanya ke pengguna. */
    public function voidBlocker(): ?string
    {
        if ($this->status !== 'posted') {
            return 'Dokumen sudah di-void.';
        }
        if ($this->canBeVoided()) {
            return null;
        }

        $saldo = (float) CustomerOverpayment::where('customer_id', $this->customer_id)->sum('amount');

        return 'Saldo kredit pelanggan tinggal ' . rupiah($saldo) . ' — sebagian sudah terpakai membayar faktur, '
            . 'jadi kredit ' . rupiah($this->amount) . ' ini tidak bisa ditarik kembali. Void pembayaran yang memakainya lebih dulu.';
    }
}
