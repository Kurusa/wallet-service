<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

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

    public static function getTechnicalUser()
    {
        return static::firstOrCreate([
            'email' => 'technical@example.com'
        ], [
            'name' => 'Technical User',
            'password' => bcrypt('password'),
        ]);
    }
}
