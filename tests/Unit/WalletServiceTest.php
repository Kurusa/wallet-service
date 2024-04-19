<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\WalletBalanceDTO;
use App\Models\User;
use App\Models\Currency;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\LockAcquisitionException;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $walletService;
    protected User $user;
    protected Currency $currency;
    protected Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = new WalletService();

        $this->user = User::factory()->create();

        $this->currency = Currency::factory()->create();

        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->currency->id,
            'balance' => 1000,
        ]);
    }

    public function testGetBalanceReturnsCorrectBalance()
    {
        $user = User::factory()->create();
        $currency = Currency::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'balance' => 1500
        ]);

        Cache::shouldReceive('remember')->once()->andReturn($wallet->balance);

        $balance = $this->walletService->getBalance($user, $currency);

        $this->assertEquals(1500, $balance);
    }

    public function testGetBalanceFromCache()
    {
        Cache::shouldReceive('remember')->once()->andReturn(1200);

        $balance = $this->walletService->getBalance($this->user, $this->currency);

        $this->assertEquals(1200, $balance);
    }

    public function testGetBalanceHandlesNonExistentWallet()
    {
        Cache::shouldReceive('remember')->once()->andReturn(0);

        $balance = $this->walletService->getBalance($this->user, $this->currency);

        $this->assertEquals(0, $balance);
    }

    public function testUpdateBalanceIncreasesWalletBalance()
    {
        DB::shouldReceive('transaction')->andReturnUsing(function ($callback) {
            return $callback();
        });

        Cache::shouldReceive('lock')->andReturnSelf();
        Cache::shouldReceive('get')->andReturn(true);
        Cache::shouldReceive('release')->once();
        Cache::shouldReceive('forget')->once();

        $updatedWallet = $this->walletService->updateBalance($this->user, $this->currency, 500, 'tx124');

        $this->assertEquals(1500, $updatedWallet->balance);
    }

    public function testUpdateBalanceDecreasesWalletBalance()
    {
        DB::shouldReceive('transaction')->andReturnUsing(function ($callback) {
            return $callback();
        });

        Cache::shouldReceive('lock')->andReturnSelf();
        Cache::shouldReceive('get')->andReturn(true);
        Cache::shouldReceive('release')->once();
        Cache::shouldReceive('forget')->once();

        $updatedWallet = $this->walletService->updateBalance($this->user, $this->currency, -500, 'tx125');

        $this->assertEquals(500, $updatedWallet->balance);
    }

    public function testUpdateBalanceFailsDueToInsufficientFunds()
    {
        $this->expectException(InsufficientFundsException::class);

        $user = User::factory()->create();
        $currency = Currency::factory()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'balance' => 100
        ]);

        $this->walletService->updateBalance($user, $currency, -200, 'tx126');
    }

    public function testUpdateBalanceLockFailure()
    {
        $this->expectException(LockAcquisitionException::class);

        Cache::shouldReceive('lock')->once()->andReturnSelf();
        Cache::shouldReceive('get')->once()->andReturn(false);

        $this->walletService->updateBalance($this->user, $this->currency, 500, 'tx123');
    }

    public function testTransferLockFailure()
    {
        $toUser = User::factory()->create();

        $this->expectException(LockAcquisitionException::class);

        Cache::shouldReceive('lock')->times(2)->andReturnSelf();
        Cache::shouldReceive('get')->once()->andReturn(false);

        $this->walletService->transfer($this->user, $toUser, $this->currency, 500, 'tx123');
    }

    public function testGetAllBalancesReturnsCorrectData()
    {
        $user = User::factory()->create();
        $currency1 = Currency::factory()->create(['code' => 'USD']);
        $currency2 = Currency::factory()->create(['code' => 'EUR']);

        $wallet1 = Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency1->id,
            'balance' => 1000,
        ]);

        $wallet2 = Wallet::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency2->id,
            'balance' => 2000
        ]);

        $balances = $this->walletService->getAllBalances($user);

        $expectedBalances = collect([
            new WalletBalanceDTO($currency1->code, $wallet1->balance),
            new WalletBalanceDTO($currency2->code, $wallet2->balance),
        ]);

        $this->assertCount(2, $balances);
        $this->assertEquals($expectedBalances->toArray(), $balances->toArray());
    }

    public function testGetAllBalancesReturnsEmptyCollectionForUserWithNoWallets()
    {
        $user = User::factory()->create();

        $balances = $this->walletService->getAllBalances($user);

        $this->assertInstanceOf(Collection::class, $balances);
        $this->assertTrue($balances->isEmpty());
    }
}
