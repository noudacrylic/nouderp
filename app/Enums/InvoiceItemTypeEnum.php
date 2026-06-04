<?php

namespace App\Enums;

enum InvoiceItemTypeEnum: string
{
    case PRODUCT = 'product';
    case SERVICE = 'service';
    case SHIPPING = 'shipping';
    case EXPENSE = 'expense';
}
