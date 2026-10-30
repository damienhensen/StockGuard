<?php

namespace App\Enums;

enum ApiErrorCode: string
{
    case InsufficientStock = 'INSUFFICIENT_STOCK';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';

    public function message(): string
    {
        return match ($this) {
            self::InsufficientStock => 'Not enough stock available.',
            self::IdempotencyConflict => 'This idempotency key was already used with a different quantity.',
        };
    }
}
