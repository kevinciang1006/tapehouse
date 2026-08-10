<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMetric: string
{
    case Price = 'price';
    case ChangePct = 'change_pct';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'price',
            self::ChangePct => 'change%',
        };
    }
}
