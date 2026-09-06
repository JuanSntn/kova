<?php

namespace App\Enums;

enum PlanCode: string
{
    case Free = 'free';
    case Business = 'business';
    case Restaurant = 'restaurant';

    public function level(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Business => 10,
            self::Restaurant => 20,
        };
    }
}
