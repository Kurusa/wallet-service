<?php

declare(strict_types=1);

namespace App\DTO;

class WalletBalanceDTO
{
    public string $currencyCode;

    public int $balance;

    public function __construct(public string $currencyCode, public int $balance)
    {
        $this->currencyCode = $currencyCode;
        $this->balance = $balance;
    }
}
