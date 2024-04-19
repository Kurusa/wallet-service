<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class Transaction
 *
 * @property int $id
 * @property int $from_wallet_id
 * @property int $to_wallet_id
 * @property float $amount
 * @property string $client_tx_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Wallet $fromWallet
 * @property-read Wallet $toWallet
 */
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_wallet_id',
        'to_wallet_id',
        'amount',
        'client_tx_id',
    ];

    /**
     * @return BelongsTo
     */
    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    /**
     * @return BelongsTo
     */
    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }
}
