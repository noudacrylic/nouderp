<?php

namespace App\Enums;

enum BillingStatusEnum: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case VOID = 'void';
}
