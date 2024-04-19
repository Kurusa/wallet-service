<?php

namespace App\Enums;

enum OperationDirection: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}
