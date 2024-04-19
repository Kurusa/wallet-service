<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\WalletBalanceDTO;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\LockAcquisitionException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Currency;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class WalletService
{
    const DEFAULT_TTL = 60 * 60;

    public function getBalance(User $user, Currency $currency): int
    {
        $cacheKey = $this->getCacheKey($user, $currency);
        return Cache::remember($cacheKey, self::DEFAULT_TTL, function () use ($user, $currency) {
            return $user->wallets()->where('currency_id', $currency->id)->first()?->balance ?? 0;
        });
    }

    public function getAllBalances(User $user)
    {
        return $user->wallets()->with('currency')->get()->map(function (Wallet $wallet) {
            return new WalletBalanceDTO($wallet->currency->code, $wallet->balance);
        });
    }

    public function updateBalance(User $user, Currency $currency, int $amount, string $clientTxId): Wallet
    {
        $lock = Cache::lock('updateBalance:' . $clientTxId, 10);

        if ($lock->get()) {
            try {
                return DB::transaction(function () use ($user, $currency, $amount, $clientTxId, $lock) {
                    $wallet = $user->wallets()->firstOrCreate([
                        'currency_id' => $currency->id,
                        'is_technical' => false,
                    ], [
                        'balance' => 0
                    ]);

                    if ($wallet->balance + $amount < 0) {
                        throw new InsufficientFundsException("Insufficient funds for user id: {$user->id}, currency: {$currency->code}, amount requested: {$amount}");
                    }

                    $wallet->balance += $amount;
                    $wallet->save();

                    Cache::forget($this->getCacheKey($user, $currency));

                    return $wallet;
                });
            } finally {
                $lock->release();
            }
        } else {
            throw new LockAcquisitionException("Unable to get lock for transaction: {$clientTxId}");
        }
    }

    public function transfer(User $fromUser, User $toUser, Currency $currency, int $amount, string $clientTxId): void
    {
        $lock = Cache::lock('transfer:' . $clientTxId, 10);

        if ($lock->get()) {
            try {
                DB::transaction(function () use ($fromUser, $toUser, $currency, $amount, $clientTxId) {
                    $this->updateBalance($fromUser, $currency, -$amount, $clientTxId);
                    $this->updateBalance($toUser, $currency, $amount, $clientTxId);

                    $techWalletDebit = Wallet::findOrCreateTechnicalWallet($currency->id, 'debit');
                    $techWalletCredit = Wallet::findOrCreateTechnicalWallet($currency->id, 'credit');

                    $this->updateBalance($techWalletDebit->user, $currency, $amount, $clientTxId);
                    $this->updateBalance($techWalletCredit->user, $currency, -$amount, $clientTxId);

                    $this->recordTransaction($techWalletDebit, $techWalletCredit, $amount, $clientTxId);
                });
            } finally {
                $lock->release();
            }
        } else {
            throw new LockAcquisitionException("Unable to get lock for transaction: {$clientTxId}");
        }
    }

    public function getCacheKey(User $user, Currency $currency): string
    {
        return "balance:user_{$user->id}:currency_{$currency->id}";
    }

    protected function recordTransaction(Wallet $techWalletDebit, Wallet $techWalletCredit, int $amount, string $clientTxId): void
    {
        Transaction::create([
            'from_wallet_id' => $techWalletDebit->id,
            'to_wallet_id' => $techWalletCredit->id,
            'amount' => $amount,
            'client_tx_id' => $clientTxId,
        ]);
    }
}
