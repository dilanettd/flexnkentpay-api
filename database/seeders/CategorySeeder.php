<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run()
    {
        $categories = [
            ['name' => 'Électronique', 'description' => 'Appareils électroniques, gadgets, téléphones, etc.'],
            ['name' => 'Mode & Vêtements', 'description' => 'Habits, chaussures, accessoires pour homme et femme.'],
            ['name' => 'Maison & Cuisine', 'description' => 'Articles pour la maison, meubles, ustensiles de cuisine.'],
            ['name' => 'Beauté & Santé', 'description' => 'Produits de beauté, soins du corps, compléments.'],
            ['name' => 'Bébés & Enfants', 'description' => 'Articles pour bébés, vêtements et jouets pour enfants.'],
            ['name' => 'Sports & Loisirs', 'description' => 'Matériel sportif, équipements de fitness, activités de loisirs.'],
            ['name' => 'Informatique', 'description' => 'Ordinateurs, accessoires, périphériques.'],
            ['name' => 'Téléphonie', 'description' => 'Smartphones, accessoires, recharge.'],
            ['name' => 'Alimentation', 'description' => 'Produits alimentaires, boissons, épicerie.'],
            ['name' => 'Auto & Moto', 'description' => 'Pièces détachées, entretien, accessoires de véhicules.'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['name' => $category['name']], $category);
        }
    }
}
