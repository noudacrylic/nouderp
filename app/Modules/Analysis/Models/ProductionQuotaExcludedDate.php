<?php

namespace App\Modules\Analysis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hari yang tidak ikut merata-rata karena datanya RUSAK — bukan karena angkanya jelek.
 *
 * Bedanya penting: hari sepi tetap harus ikut, karena sepi itu kenyataan. Yang dikecualikan hanya
 * hari yang produksinya berjalan tapi tidak terekam sama sekali, sehingga memasukkannya berarti
 * menghukum pabrik atas kegagalan pencatatan.
 */
class ProductionQuotaExcludedDate extends Model
{
    protected $table = 'production_quota_excluded_dates';

    protected $fillable = ['tanggal', 'reason'];

    protected $casts = ['tanggal' => 'date'];
}
