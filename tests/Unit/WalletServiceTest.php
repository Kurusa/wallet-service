<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OperationDirection;
use App\Models\User;
use App\Models\Currency;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Exceptions\LockAcquisitionException;
use InvalidArgumentException;
use Mockery;
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

    public function testGetBalanceCaching()
    {
        Cache::shouldReceive('remember')
            ->once()
            ->with("balance:user_{$this->user->id}:currency_{$this->currency->id}", WalletService::DEFAULT_CACHE_TTL_SECONDS, Mockery::any())
            ->andReturn(1000);

        $balance = $this->walletService->getBalance($this->user, $this->currency);
        $this->assertEquals(1000, $balance);
    }

    public function testRejectNegativeTransactionAmounts()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->walletService->updateBalance($this->user, $this->currency, -100, OperationDirection::DEPOSIT);
        $this->walletService->updateBalance($this->user, $this->currency, -200, OperationDirection::WITHDRAWAL);
    }

    public function testConcurrentUpdateCausesLockTimeout()
    {
        Cache::shouldReceive('lock')->andReturnSelf();
        Cache::shouldReceive('get')->andReturn(false);

        $this->expectException(LockAcquisitionException::class);

        $this->walletService->updateBalance($this->user, $this->currency, 100, OperationDirection::DEPOSIT);
    }

    public function testGetAllBalancesForNewUser()
    {
        $newUser = User::factory()->create();

        $balances = $this->walletService->getAllBalances($newUser);

        $this->assertInstanceOf(Collection::class, $balances);
        $this->assertCount(0, $balances);
    }
}
