<?php

namespace App\Core\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Journal\JournalLine;

class Account extends Model
{
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_id',
        'normal_balance',
        'is_control_account',
        'is_system_account',
        'is_system',
        'is_active',
        'is_cash_account',
        'account_category',
    ];

    public function isCash()
    {
        return in_array($this->account_category, ['cash', 'cash_equivalent']);
    }

    public function isReceivable()
    {
        return $this->account_category === 'receivable';
    }

    public function isInventory()
    {
        return $this->account_category === 'inventory';
    }

    /**
     * Akun beban default untuk biaya admin BANK (transfer antar bank, rekonsiliasi,
     * bayar gaji, absensi).
     *
     * Dulu tiap controller memanggil `where('account_category','bank_admin_fee')->value('id')`
     * tanpa urutan. Kategori itu ternyata dipakai beberapa akun (5290 Midtrans, 5291
     * Biteship), jadi baris mana yang terambil bergantung urutan tabel — form transfer
     * bisa terisi "Beban Midtrans Gateway" dan operator yang lupa menggantinya membukukan
     * biaya bank ke akun gateway. Karena itu defaultnya dikunci ke 5103 Beban
     * Administrasi Bank, dan fallback-nya diurutkan supaya hasilnya tidak berubah-ubah.
     */
    public static function bankAdminFeeDefaultId(): ?int
    {
        $id = static::where('is_active', 1)->where('type', 'expense')->where('code', '5103')->value('id')
            ?? static::where('is_active', 1)->where('type', 'expense')
                ->where('account_category', 'bank_admin_fee')->orderBy('code')->value('id');

        return $id ? (int) $id : null;
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines()
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
