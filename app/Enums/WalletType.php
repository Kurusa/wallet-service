<?php

namespace App\Enums;

enum WalletType: string
{
    case CREDIT = 'credit';
    case DEBIT = 'debit';
    case NORMAL = 'normal';
}
