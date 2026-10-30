<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Product::firstOrCreate(
            ['sku' => 'GPU-RTX-001'],
            [
                'name' => 'Demo Graphics Card',
                'initial_stock' => 10,
                'available_quantity' => 10
            ],
        );
    }
}
