<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Fee;

class FeesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Frais de commande
        Fee::create([
            'name' => 'Frais de commande',
            'type' => 'order',
            'percentage' => 1.5,
            'is_active' => true,
        ]);

        Fee::create([
            'name' => 'Frais de pénalité pour retard',
            'type' => 'penalty',
            'percentage' => 1.5,
            'is_active' => true,
        ]);
    }
}