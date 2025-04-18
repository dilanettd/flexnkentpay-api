<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_name',
        'total_deposit_amount',
        'total_withdrawal_amount',
        'total_transactions',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    /**
     * Met à jour les statistiques d'utilisation pour les dépôts.
     *
     * @param string $providerName
     * @param float $amount Montant déjà au bon format
     * @return void
     */
    public static function updateDepositUsage(string $providerName, float $amount): void
    {
        $usage = self::firstOrNew(['provider_name' => $providerName]);

        $usage->total_deposit_amount = ($usage->total_deposit_amount ?? 0) + $amount;
        $usage->total_transactions = ($usage->total_transactions ?? 0) + 1;
        $usage->last_used_at = now();

        $usage->save();
    }

    /**
     * Met à jour les statistiques d'utilisation pour les retraits.
     *
     * @param string $providerName
     * @param float $amount Montant déjà au bon format
     * @return void
     */
    public static function updateWithdrawalUsage(string $providerName, float $amount): void
    {
        $usage = self::firstOrNew(['provider_name' => $providerName]);

        $usage->total_withdrawal_amount = ($usage->total_withdrawal_amount ?? 0) + $amount;
        $usage->total_transactions = ($usage->total_transactions ?? 0) + 1;
        $usage->last_used_at = now();

        $usage->save();
    }
}