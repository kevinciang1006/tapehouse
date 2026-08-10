<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetType: string
{
    case Stock = 'stock';
    case Forex = 'forex';
    case Crypto = 'crypto';

    /**
     * Fallback display precision. Individual symbols override this via
     * symbols.price_decimals, because XAU/USD quotes to 2 places while
     * most forex pairs quote to 5.
     */
    public function defaultDecimals(): int
    {
        return match ($this) {
            self::Stock, self::Crypto => 2,
            self::Forex => 5,
        };
    }
}
