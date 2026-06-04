<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class TaskSetting extends Model
{
    protected $fillable = [
        'printing_default_user_id',
    ];

    public function printingDefaultUser()
    {
        return $this->belongsTo(User::class, 'printing_default_user_id');
    }

    public static function current(): self
    {
        $row = static::query()->orderBy('id')->first();
        if ($row) return $row;
        return static::create([]);
    }
}
