<?php

namespace App\Enums;

enum InvoiceStatusEnum: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case VOID = 'void';
}
