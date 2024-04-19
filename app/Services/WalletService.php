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
     * @param string $clientTxId
     * @return Wallet
     * @throws LockAcquisitionException
     */
    public function updateBalance(User $user, Currency $currency, int $amount, string $clientTxId): Wallet
    {
        $lock = Cache::lock($this->getLockKey($user, $currency), 10);

        if (!$lock->get()) {
            throw new LockAcquisitionException("Unable to get lock for transaction: {$clientTxId}");
        }

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

                $techWallet = $this->updateTechnicalWallet($currency, $amount, $clientTxId);

                $this->recordTransaction($wallet, $techWallet, $amount, $clientTxId);

                Cache::forget($this->getCacheKey($user, $currency));

                return $wallet;
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
     * @param string $clientTxId
     * @return void
     * @throws LockAcquisitionException
     */
    public function transfer(User $fromUser, User $toUser, Currency $currency, int $amount, string $clientTxId): void
    {
        $fromLock = Cache::lock($this->getLockKey($fromUser, $currency), 10);
        $toLock = Cache::lock($this->getLockKey($toUser, $currency), 10);

        if (!$fromLock->get() || !$toLock->get()) {
            throw new LockAcquisitionException("Unable to get lock for transaction: {$clientTxId}");
        }
        
        try {
            DB::transaction(function () use ($fromUser, $toUser, $currency, $amount, $clientTxId) {
                $this->updateBalance($fromUser, $currency, -$amount, $clientTxId);
                $this->updateBalance($toUser, $currency, $amount, $clientTxId);
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
     * @param Wallet $techWalletDebit
     * @param Wallet $techWalletCredit
     * @param int $amount
     * @param string $clientTxId
     * @return void
     */
    protected function recordTransaction(Wallet $techWalletDebit, Wallet $techWalletCredit, int $amount, string $clientTxId): void
    {
        Transaction::create([
            'from_wallet_id' => $techWalletDebit->id,
            'to_wallet_id' => $techWalletCredit->id,
            'amount' => $amount,
            'client_tx_id' => $clientTxId,
        ]);
    }

    /**
     * @param Currency $currency
     * @param int $amount
     * @param string $clientTxId
     * @return Wallet
     */
    private function updateTechnicalWallet(Currency $currency, int $amount, string $clientTxId): Wallet
    {
        $techWalletType = $amount >= 0 ? 'credit' : 'debit';
        $techWallet = Wallet::findOrCreateTechnicalWallet($currency->id, $techWalletType);
        $techWallet->balance -= $amount;
        $techWallet->save();

        return $techWallet;
    }
}
