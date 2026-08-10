<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertCondition: string
{
    case Above = 'above';
    case Below = 'below';

    /**
     * Strict comparison on purpose: a rule sitting exactly on its threshold
     * must not fire, or a price resting on a round number retriggers on every
     * tick until the cooldown absorbs it.
     */
    public function isSatisfiedBy(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value > $threshold,
            self::Below => $value < $threshold,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Above => '>',
            self::Below => '<',
        };
    }
}
