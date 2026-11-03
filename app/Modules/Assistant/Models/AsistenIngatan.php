<?php

namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Model;

/** Aturan tetap yang dinyatakan user ke bot Telegram; ikut di system prompt tiap percakapan. */
class AsistenIngatan extends Model
{
    protected $table = 'asisten_ingatan';

    protected $fillable = ['user_id', 'isi'];
}
