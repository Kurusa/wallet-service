<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\WalletBalanceDTO;
use App\Enums\OperationDirection;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\LockAcquisitionException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Currency;
use App\Models\Wallet;
use Illuminate\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class WalletService
{
    const DEFAULT_CACHE_TTL_SECONDS = 60 * 60; // 1 hour
    const DEFAULT_LOCK_TTL_SECONDS = 10;

    /**
     * @param User $user
     * @param Currency $currency
     * @return int
     */
    public function getBalance(User $user, Currency $currency): int
    {
        $cacheKey = $this->getCacheKey($user, $currency);

        return Cache::remember($cacheKey, self::DEFAULT_CACHE_TTL_SECONDS, function () use ($user, $currency) {
            return $user->getWalletByCurrency($currency)->balance;
        });
    }

    /**
     * @param User $user
     * @return Collection<WalletBalanceDTO>
     */
    public function getAllBalances(User $user): Collection
    {
        return $user->wallets()->with('currency')->get()->map(function (Wallet $wallet) {
            return new WalletBalanceDTO($wallet->currency->code, $wallet->balance);
        });
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @param int $amount
     * @param OperationDirection $direction
     * @return void
     * @throws InsufficientFundsException
     * @throws LockAcquisitionException
     * @throws InvalidArgumentException
     */
    public function updateBalance(User $user, Currency $currency, int $amount, OperationDirection $direction): void
    {
        $this->executeTransaction($user, Wallet::findOrCreateTechnicalWallet($currency), $currency, $amount, $direction);
    }

    /**
     * @param User $fromUser
     * @param User $toUser
     * @param Currency $currency
     * @param int $amount
     * @return void
     * @throws InsufficientFundsException
     * @throws LockAcquisitionException
     * @throws InvalidArgumentException
     */
    public function transfer(User $fromUser, User $toUser, Currency $currency, int $amount): void
    {
        $this->executeTransaction($fromUser, $toUser->getWalletByCurrency($currency), $currency, $amount, OperationDirection::DEPOSIT);
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @return string
     */
    private function getCacheKey(User $user, Currency $currency): string
    {
        return "balance:user_{$user->id}:currency_{$currency->id}";
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @return string
     */
    private function getLockKey(User $user, Currency $currency): string
    {
        return "walletLock:user_{$user->id}:currency_{$currency->id}";
    }

    /**
     * @param User $fromUser
     * @param Wallet $toWallet
     * @param Currency $currency
     * @param int $amount
     * @param OperationDirection $direction
     * @return void
     * @throws InsufficientFundsException
     * @throws LockAcquisitionException
     * @throws InvalidArgumentException
     */
    private function executeTransaction(User $fromUser, Wallet $toWallet, Currency $currency, int $amount, OperationDirection $direction): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Transaction amounts must be non-negative.");
        }

        $fromWallet = $fromUser->getWalletByCurrency($currency);

        if (!$fromWallet->is_technical && ($fromWallet->balance + $amount < 0)) {
            throw new InsufficientFundsException("Insufficient funds for user id: {$fromWallet->user->id}, currency: {$fromWallet->currency->code}, amount requested: {$amount}");
        }

        $locks = [
            Cache::lock($this->getLockKey($fromUser, $currency), self::DEFAULT_LOCK_TTL_SECONDS),
            Cache::lock($this->getLockKey($toWallet->user, $currency), self::DEFAULT_LOCK_TTL_SECONDS),
        ];

        /** @var Lock $lock */
        foreach ($locks as $lock) {
            if (!$lock->get()) {
                throw new LockAcquisitionException("Unable to acquire lock");
            }
        }

        try {
            if ($direction === OperationDirection::WITHDRAWAL) {
                $this->doTransfer($fromWallet, $toWallet, -$amount);
            } else {
                $this->doTransfer($fromWallet, $toWallet, $amount);
            }

            Cache::forget($this->getCacheKey($fromWallet->user, $currency));
            Cache::forget($this->getCacheKey($toWallet->user, $currency));
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * @param Wallet $fromWallet
     * @param Wallet $toWallet
     * @param int $amount
     * @return void
     */
    private function doTransfer(Wallet $fromWallet, Wallet $toWallet, int $amount): void
    {
        DB::transaction(function () use ($fromWallet, $toWallet, $amount) {
            $fromWallet->balance -= $amount;
            $toWallet->balance += $amount;

            $fromWallet->save();
            $toWallet->save();

            $this->recordTransaction($fromWallet, $toWallet, $amount);
        });
    }

    /**
     * @param Wallet $fromWallet
     * @param Wallet $toWallet
     * @param int $amount
     * @return void
     */
    protected function recordTransaction(Wallet $fromWallet, Wallet $toWallet, int $amount): void
    {
        Transaction::create([
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'amount' => $amount,
        ]);
    }
}
