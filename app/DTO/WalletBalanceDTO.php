<?php

declare(strict_types=1);

namespace App\DTO;

readonly class WalletBalanceDTO
{
    public function __construct(public string $currencyCode, public int $balance)
    {
    }
}
