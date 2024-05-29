<?php

declare(strict_types=1);

namespace App\Enums;

enum OperationDirection: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}
