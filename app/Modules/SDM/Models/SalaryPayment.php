<?php

namespace App\Modules\SDM\Models;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use Illuminate\Database\Eloquent\Model;

class SalaryPayment extends Model
{
    protected $table = 'sdm_salary_payment';

    protected $fillable = [
        'number', 'date', 'karyawan_id', 'periode_bulan', 'periode_tahun',
        'cash_account_id',
        'bruto_gaji', 'bpjs_kes_employee', 'bpjs_kes_company',
        'bpjs_tk_employee', 'bpjs_tk_company', 'pph21_amount',
        'kasbon_potongan', 'nett_dibayar',
        'admin_fee', 'admin_fee_account_id',
        'status', 'journal_id', 'posted_at', 'voided_at', 'void_reason',
        'notes', 'created_by',
    ];

    protected $casts = [
        'date'              => 'date',
        'posted_at'         => 'datetime',
        'voided_at'         => 'datetime',
        'bruto_gaji'        => 'decimal:2',
        'bpjs_kes_employee' => 'decimal:2',
        'bpjs_kes_company'  => 'decimal:2',
        'bpjs_tk_employee'  => 'decimal:2',
        'bpjs_tk_company'   => 'decimal:2',
        'pph21_amount'      => 'decimal:2',
        'kasbon_potongan'   => 'decimal:2',
        'nett_dibayar'      => 'decimal:2',
        'admin_fee'         => 'decimal:2',
    ];

    public function karyawan()       { return $this->belongsTo(Karyawan::class); }
    public function cashAccount()    { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function adminFeeAccount(){ return $this->belongsTo(Account::class, 'admin_fee_account_id'); }
    public function journal()        { return $this->belongsTo(Journal::class); }

    public function isDraft(): bool  { return $this->status === 'draft'; }
    public function isPosted(): bool { return $this->status === 'posted'; }
    public function isVoid(): bool   { return $this->status === 'void'; }

    public function canBeEdited(): bool { return $this->isDraft(); }
    public function canBeVoided(): bool { return $this->isPosted(); }

    public function periodeLabel(): string
    {
        $bulanNama = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
        return ($bulanNama[$this->periode_bulan] ?? '?') . ' ' . $this->periode_tahun;
    }
}
