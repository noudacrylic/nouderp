<?php

namespace App\Modules\FixedAsset\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class FixedAssetSetting extends Model
{
    protected $fillable = [
        'opening_offset_account_id',
        'default_disposal_proceeds_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
        'auto_run_depreciation_on_close',
    ];

    protected $casts = [
        'auto_run_depreciation_on_close' => 'boolean',
    ];

    public function openingOffsetAccount()
    {
        return $this->belongsTo(Account::class, 'opening_offset_account_id');
    }

    public function defaultDisposalProceedsAccount()
    {
        return $this->belongsTo(Account::class, 'default_disposal_proceeds_account_id');
    }

    public function disposalGainAccount()
    {
        return $this->belongsTo(Account::class, 'disposal_gain_account_id');
    }

    public function disposalLossAccount()
    {
        return $this->belongsTo(Account::class, 'disposal_loss_account_id');
    }

    /**
     * Singleton row. Field yang masih null diisi default dari COA standar (sekali jalan,
     * supaya admin bisa edit kemudian tanpa selalu di-overwrite).
     */
    public static function instance(): self
    {
        $row = static::first();

        if (!$row) {
            $row = static::create([
                'opening_offset_account_id' => Account::where('code', '3100')->value('id'),
                'default_disposal_proceeds_account_id' => Account::where('code', '1101')->value('id'),
                'disposal_gain_account_id' => Account::where('code', '7100')->value('id'),
                'disposal_loss_account_id' => Account::where('code', '7200')->value('id'),
                'auto_run_depreciation_on_close' => true,
            ]);
        } else {
            // Backfill default kalau field baru masih null (mis. row dari migration sebelumnya).
            $changed = false;
            if (!$row->disposal_gain_account_id) {
                $row->disposal_gain_account_id = Account::where('code', '7100')->value('id');
                $changed = true;
            }
            if (!$row->disposal_loss_account_id) {
                $row->disposal_loss_account_id = Account::where('code', '7200')->value('id');
                $changed = true;
            }
            if ($changed) $row->save();
        }

        return $row;
    }
}
