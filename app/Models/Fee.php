<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'percentage',
        'is_active'
    ];

    /**
     * Récupère les frais actifs d'un certain type.
     * 
     * @param string $type
     * @return float
     */
    public static function getActivePercentage(string $type): float
    {
        $fee = self::where('type', $type)
            ->where('is_active', true)
            ->first();

        return $fee ? $fee->percentage : 0;
    }
}