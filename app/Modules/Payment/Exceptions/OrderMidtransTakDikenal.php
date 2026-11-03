<?php

namespace App\Modules\Payment\Exceptions;

use RuntimeException;

/** Notifikasi Midtrans untuk order_id yang bukan order aktif transaksi mana pun. */
class OrderMidtransTakDikenal extends RuntimeException
{
    public function __construct(string $orderId)
    {
        parent::__construct("MidtransTransaction tidak ditemukan: {$orderId}");
    }
}
