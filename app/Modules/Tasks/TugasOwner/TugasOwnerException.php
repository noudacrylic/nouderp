<?php

namespace App\Modules\Tasks\TugasOwner;

use RuntimeException;

class TugasOwnerException extends RuntimeException
{
    public function __construct(string $pesan, public readonly int $status = 422)
    {
        parent::__construct($pesan);
    }
}
