<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Currency;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class WalletService
{
    public function getBalance(User $user, Currency $currency): float
    {
        $cacheKey = $this->getCacheKey($user, $currency);

        return Cache::remember($cacheKey, 3600, function () use ($user, $currency) {
            return $user->wallets()->where('currency_id', $currency->id)->first()?->balance ?? 0;
        });
    }

    public function getAllBalances(User $user)
    {
        return $user->wallets()->with('currency')->get()->mapWithKeys(function (Wallet $wallet) {
            return [$wallet->currency->code => $wallet->balance];
        });
    }

    public function updateBalance(User $user, Currency $currency, $amount, $clientTxId): Wallet
    {
        return DB::transaction(function () use ($user, $currency, $amount, $clientTxId) {
            $wallet = $user->wallets()->lockForUpdate()->firstOrCreate([
                'currency_id' => $currency->id,
                'is_technical' => false,
            ], [
                'balance' => 0
            ]);

            if ($wallet->balance + $amount < 0) {
                throw new InsufficientFundsException("Insufficient funds");
            }

            $wallet->balance += $amount;
            $wallet->save();

            Cache::forget($this->getCacheKey($user, $currency));

            return $wallet;
        });
    }

    public function transfer(User $fromUser, User $toUser, Currency $currency, $amount, $clientTxId): void
    {
        DB::transaction(function () use ($fromUser, $toUser, $currency, $amount, $clientTxId) {
            $this->updateBalance($fromUser, $currency, -$amount, $clientTxId);
            $this->updateBalance($toUser, $currency, $amount, $clientTxId);

            $techWalletDebit = Wallet::findOrCreateTechnicalWallet($currency->id, 'debit');
            $techWalletCredit = Wallet::findOrCreateTechnicalWallet($currency->id, 'credit');

            $this->updateBalance($techWalletDebit->user, $currency, $amount, $clientTxId);
            $this->updateBalance($techWalletCredit->user, $currency, -$amount, $clientTxId);

            $this->recordTransaction($techWalletDebit, $techWalletCredit, $amount, $clientTxId);
        });
    }

    public function getCacheKey(User $user, Currency $currency): string
    {
        return "balance:user_{$user->id}:currency_{$currency->id}";
    }

    protected function recordTransaction(Wallet $techWalletDebit, Wallet $techWalletCredit, $amount, string $clientTxId): void
    {
        Transaction::create([
            'from_wallet_id' => $techWalletDebit->id,
            'to_wallet_id' => $techWalletCredit->id,
            'amount' => $amount,
            'client_tx_id' => $clientTxId,
        ]);
    }
}
