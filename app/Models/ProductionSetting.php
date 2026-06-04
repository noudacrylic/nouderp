<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionSetting extends Model
{
    protected $table = 'production_settings';

    protected $fillable = ['score_sales_period'];

    protected $casts = ['score_sales_period' => 'integer'];

    public static function getSalesPeriod(): int
    {
        $setting = static::first();
        return $setting ? (int) $setting->score_sales_period : 1;
    }

    public static function upsert(int $salesPeriod): void
    {
        $setting = static::first() ?? new static();
        $setting->score_sales_period = $salesPeriod;
        $setting->save();
    }
}
