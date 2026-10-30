<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'initial_stock',
        'available_quantity',
    ];

    protected function casts(): array
    {
        return [
            'initial_stock' => 'integer',
            'available_quantity' => 'integer',
        ];
    }
}
