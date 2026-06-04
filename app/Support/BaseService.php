<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

abstract class BaseService
{
    /**
     * Run a database transaction.
     *
     * @param callable $callback
     * @return mixed
     *
     * @throws \Throwable
     */
    protected function transaction(callable $callback)
    {
        return DB::transaction($callback);
    }
}
