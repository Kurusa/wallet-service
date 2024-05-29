<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Class User
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Collection|Wallet[] $wallets
 */
class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public static function getTechnicalUser(): User
    {
        return static::firstOrCreate([
            'email' => 'technical@example.com'
        ], [
            'name' => 'Technical User',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * @param Currency $currency
     * @return Wallet
     */
    public function getWalletByCurrency(Currency $currency): Wallet
    {
        return $this->wallets()->firstOrCreate([
            'currency_id' => $currency->id,
            'is_technical' => false,
        ], [
            'balance' => 0,
        ]);
    }
}
