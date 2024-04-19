<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'currency_id',
        'balance',
        'is_technical',
        'wallet_type',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'from_wallet_id')
            ->orWhere('to_wallet_id', $this->id);
    }

    public static function findOrCreateTechnicalWallet(int $currencyId, string $type)
    {
        return static::lockForUpdate()->firstOrCreate([
            'user_id' => User::getTechnicalUser()->id,
            'currency_id' => $currencyId,
            'is_technical' => true,
            'wallet_type' => $type
        ], [
            'balance' => 0,
        ]);
    }
}
