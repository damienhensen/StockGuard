<?php

namespace App\Enums;

enum ApiErrorCode: string
{
    case InsufficientStock = 'INSUFFICIENT_STOCK';

    public function message(): string
    {
        return match ($this) {
            self::InsufficientStock => 'Not enough stock available.',
        };
    }
}
