<?php

namespace App\DTO;

class WalletBalanceDTO
{
    public string $currencyCode;

    public float $balance;

    public function __construct(string $currencyCode, float $balance)
    {
        $this->currencyCode = $currencyCode;
        $this->balance = $balance;
    }
}
