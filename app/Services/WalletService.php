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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class WalletService
{
    const DEFAULT_TTL = 60 * 60;

    /**
     * @param User $user
     * @param Currency $currency
     * @return int
     */
    public function getBalance(User $user, Currency $currency): int
    {
        $cacheKey = $this->getCacheKey($user, $currency);

        return Cache::remember($cacheKey, self::DEFAULT_TTL, function () use ($user, $currency) {
            return $user->wallets()->where('currency_id', $currency->id)->first()?->balance ?? 0;
        });
    }

    /**
     * @param User $user
     * @return Collection
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
     * @throws LockAcquisitionException
     */
    public function updateBalance(User $user, Currency $currency, int $amount, OperationDirection $direction): void
    {
        $lock = Cache::lock($this->getLockKey($user, $currency), 10);

        if (!$lock->get()) {
            throw new LockAcquisitionException("Unable to get lock for user id: {$user->id}, currency: {$currency->code}, amount requested: {$amount}");
        }

        try {
            DB::transaction(function () use ($user, $currency, $amount, $direction) {
                $userWallet = $user->getWalletByCurrency($currency);

                $techWallet = Wallet::findOrCreateTechnicalWallet($currency);

                if ($direction === OperationDirection::DEPOSIT) {
                    $this->doTransfer($techWallet, $userWallet, $amount);
                } else {
                    $this->doTransfer($userWallet, $techWallet, $amount);
                }

                Cache::forget($this->getCacheKey($user, $currency));
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @param User $fromUser
     * @param User $toUser
     * @param Currency $currency
     * @param int $amount
     * @return void
     * @throws LockAcquisitionException
     */
    public function transfer(User $fromUser, User $toUser, Currency $currency, int $amount): void
    {
        $fromLock = Cache::lock($this->getLockKey($fromUser, $currency), 10);
        $toLock = Cache::lock($this->getLockKey($toUser, $currency), 10);

        if (!$fromLock->get() || !$toLock->get()) {
            throw new LockAcquisitionException("Unable to get lock for transaction");
        }
        
        try {
            DB::transaction(function () use ($fromUser, $toUser, $currency, $amount) {
                $fromWallet = $fromUser->getWalletByCurrency($currency);
                $toWallet = $toUser->getWalletByCurrency($currency);

                $this->doTransfer($fromWallet, $toWallet, $amount);
            });
        } finally {
            $fromLock->release();
            $toLock->release();
        }
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @return string
     */
    public function getCacheKey(User $user, Currency $currency): string
    {
        return "balance:user_{$user->id}:currency_{$currency->id}";
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @return string
     */
    public function getLockKey(User $user, Currency $currency): string
    {
        return "walletLock:user_{$user->id}:currency_{$currency->id}";
    }

    /**
     * @param Wallet $fromWallet
     * @param Wallet $toWallet
     * @param int $amount
     * @return void
     * @throws InsufficientFundsException
     */
    private function doTransfer(Wallet $fromWallet, Wallet $toWallet, int $amount): void
    {
        if (!$fromWallet->is_technical) {
            if ($fromWallet->balance + $amount < 0) {
                throw new InsufficientFundsException("Insufficient funds for user id: {$fromWallet->user->id}, currency: {$fromWallet->currency->code}, amount requested: {$amount}");
            }
        }

        $fromWallet->balance -= $amount;
        $toWallet->balance += $amount;

        $fromWallet->save();
        $toWallet->save();

        $this->recordTransaction($fromWallet, $toWallet, $amount);
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
