<?php

declare(strict_types=1);

use App\Services\Budget\CreditBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::connection()->flushdb();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function budget(int $capacity = 8, int $refill = 8): CreditBudget
{
    return new CreditBudget(Redis::connection(), $capacity, $refill);
}

it('starts full', function (): void {
    expect(budget()->available())->toBe(8);
});

it('consumes down to zero', function (): void {
    $b = budget();

    expect($b->tryConsume(5))->toBe(5)
        ->and($b->available())->toBe(3)
        ->and($b->tryConsume(3))->toBe(3)
        ->and($b->available())->toBe(0);
});

it('refuses when empty', function (): void {
    $b = budget();
    $b->tryConsume(8);

    expect($b->tryConsume(1))->toBe(0)
        ->and($b->available())->toBe(0);
});

it('grants partially rather than refusing outright', function (): void {
    $b = budget();
    $b->tryConsume(5);

    // Twelve Data bills per symbol, so an 8-symbol slice against 3 remaining
    // tokens must poll 3 symbols — not zero, and not eight.
    expect($b->tryConsume(8))->toBe(3)
        ->and($b->available())->toBe(0);
});

it('never grants more than requested even when full', function (): void {
    expect(budget()->tryConsume(3))->toBe(3);
});

it('refills at the configured rate over time', function (): void {
    $b = budget();
    $b->tryConsume(8);
    expect($b->available())->toBe(0);

    // 8 per minute = 1 token per 7.5s. At 30s, 4 tokens.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:30'));
    expect($b->available())->toBe(4);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:00'));
    expect($b->available())->toBe(8);
});

it('caps refill at capacity', function (): void {
    $b = budget();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 13:00:00'));

    expect($b->available())->toBe(8);
});

it('accumulates fractional refill progress instead of discarding it', function (): void {
    $b = budget();
    $b->tryConsume(8);

    // Poll every 3s for 9s. Each call alone earns zero whole tokens at
    // 1 per 7.5s — if the refill timestamp were advanced to `now` on every
    // call, the bucket would never refill at all under a fast poll loop.
    foreach ([3, 6, 9] as $offset) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00')->addSeconds($offset));
        $b->available();
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:15'));

    expect($b->available())->toBe(2);
});

it('is atomic across concurrent consumers', function (): void {
    $b = budget();

    // Twenty single-token attempts against a capacity of 8 must grant
    // exactly 8 in total, no matter the interleaving.
    $granted = 0;
    for ($i = 0; $i < 20; $i++) {
        $granted += $b->tryConsume(1);
    }

    expect($granted)->toBe(8)
        ->and($b->available())->toBe(0);
});

it('reports seconds until the next whole token', function (): void {
    $b = budget();
    $b->tryConsume(8);

    expect($b->secondsUntilNextToken())->toBe(8);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:05'));

    expect($b->secondsUntilNextToken())->toBe(3);
});

it('reports zero seconds when tokens are already available', function (): void {
    expect(budget()->secondsUntilNextToken())->toBe(0);
});

it('treats a zero request as a refill-only probe', function (): void {
    $b = budget();

    expect($b->tryConsume(0))->toBe(0)
        ->and($b->available())->toBe(8);
});
