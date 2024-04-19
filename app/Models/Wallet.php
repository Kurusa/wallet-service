<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Class Wallet
 *
 * @property int $id
 * @property int $user_id
 * @property int $currency_id
 * @property int $balance
 * @property boolean $is_technical
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read User $user
 * @property-read Currency $currency
 * @property-read Collection|Transaction[] $transactions
 * @property-read int $transactions_count
 */
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'currency_id',
        'balance',
        'is_technical',
    ];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'from_wallet_id')
            ->orWhere('to_wallet_id', $this->id);
    }

    /**
     * @param Currency $currency
     * @return Wallet
     */
    public static function findOrCreateTechnicalWallet(Currency $currency): Wallet
    {
        return static::firstOrCreate([
            'user_id' => User::getTechnicalUser()->id,
            'currency_id' => $currency->id,
            'is_technical' => true,
        ], [
            'balance' => 0,
        ]);
    }
}
