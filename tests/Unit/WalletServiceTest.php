<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\WalletBalanceDTO;
use App\Exceptions\InsufficientFundsException;
use Tests\TestCase;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Currency;
use App\Models\Wallet;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $walletService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = new WalletService();
    }

    public function testGetBalance()
    {
        $user = User::factory()->create();
        $currency = Currency::factory()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'balance' => 100,
        ]);

        $balance = $this->walletService->getBalance($user, $currency);
        $this->assertEquals(100, $balance);
    }

    public function testGetAllBalances()
    {
        $user = User::factory()->create();
        $currencyOne = Currency::factory()->create(['code' => 'USD']);
        $currencyTwo = Currency::factory()->create(['code' => 'EUR']);
        Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currencyOne->id,
            'balance' => 50,
        ]);
        Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currencyTwo->id,
            'balance' => 150,
        ]);

        $balances = $this->walletService->getAllBalances($user);

        $this->assertCount(2, $balances);

        $this->assertTrue($balances->contains(function (WalletBalanceDTO  $dto) {
            return $dto->currencyCode == 'USD' && $dto->balance == 50;
        }));

        $this->assertTrue($balances->contains(function (WalletBalanceDTO $dto) {
            return $dto->currencyCode == 'EUR' && $dto->balance == 150;
        }));
    }

    public function testUpdateBalance()
    {
        $user = User::factory()->create();
        $currency = Currency::factory()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'balance' => 0,
        ]);

        $updatedWallet = $this->walletService->updateBalance($user, $currency, 100, 'tx123');
        $this->assertEquals(100, $updatedWallet->balance);
    }

    public function testTransfer()
    {
        $fromUser = User::factory()->create();
        $toUser = User::factory()->create();
        $currency = Currency::factory()->create();
        $fromWallet = Wallet::factory()->create([
            'user_id' => $fromUser->id,
            'currency_id' => $currency->id,
            'balance' => 100,
        ]);
        $toWallet = Wallet::factory()->create([
            'user_id' => $toUser->id,
            'currency_id' => $currency->id,
            'balance' => 0,
        ]);

        $this->walletService->transfer($fromUser, $toUser, $currency, 50, 'tx124');

        $fromWallet->refresh();
        $toWallet->refresh();
        $this->assertEquals(50, $fromWallet->balance);
        $this->assertEquals(50, $toWallet->balance);
    }

    public function testTransferExactBalance()
    {
        $fromUser = User::factory()->create();
        $toUser = User::factory()->create();
        $currency = Currency::factory()->create();
        $balanceAmount = 100;

        $fromWallet = Wallet::factory()->create([
            'user_id' => $fromUser->id,
            'currency_id' => $currency->id,
            'balance' => $balanceAmount,
        ]);
        $toWallet = Wallet::factory()->create([
            'user_id' => $toUser->id,
            'currency_id' => $currency->id,
            'balance' => 0,
        ]);

        $this->walletService->transfer($fromUser, $toUser, $currency, $balanceAmount, 'tx125');

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(0, $fromWallet->balance);
        $this->assertEquals($balanceAmount, $toWallet->balance);
    }

    public function testTransferWithZeroBalance()
    {
        $this->expectException(InsufficientFundsException::class);

        $fromUser = User::factory()->create();
        $toUser = User::factory()->create();
        $currency = Currency::factory()->create();

        Wallet::factory()->create([
            'user_id' => $fromUser->id,
            'currency_id' => $currency->id,
            'balance' => 0,
        ]);
        Wallet::factory()->create([
            'user_id' => $toUser->id,
            'currency_id' => $currency->id,
            'balance' => 0
        ]);

        $this->walletService->transfer($fromUser, $toUser, $currency, 50, 'tx126');
    }

    public function testTransferWithInsufficientFunds()
    {
        $this->expectException(InsufficientFundsException::class);

        $fromUser = User::factory()->create();
        $toUser = User::factory()->create();
        $currency = Currency::factory()->create();
        $initialBalance = 30;

        Wallet::factory()->create([
            'user_id' => $fromUser->id,
            'currency_id' => $currency->id,
            'balance' => $initialBalance,
        ]);
        Wallet::factory()->create([
            'user_id' => $toUser->id,
            'currency_id' => $currency->id,
            'balance' => 0,
        ]);

        $this->walletService->transfer($fromUser, $toUser, $currency, 50, 'tx127');
    }
}
