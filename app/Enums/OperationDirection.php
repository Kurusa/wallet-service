<?php

namespace App\Enums;

enum OperationDirection: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';

    public function toWalletType(): string
    {
        return match($this) {
            self::DEPOSIT => WalletType::CREDIT->value,
            self::WITHDRAWAL => WalletType::DEBIT->value,
        };
    }
}
