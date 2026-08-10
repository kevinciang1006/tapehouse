<?php

declare(strict_types=1);

namespace App\Services\Budget;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis token bucket guarding the Twelve Data credit allowance.
 *
 * Twelve Data bills one credit per symbol, not per request, so callers ask for
 * as many tokens as they have symbols and act on however many they get. A
 * boolean would force the caller to either overspend or stall its whole slice.
 */
final readonly class CreditBudget
{
    private const TOKENS_KEY = 'tape:budget:tokens';

    private const REFILLED_AT_KEY = 'tape:budget:refilled_at';

    /**
     * Read-modify-write in one round trip so concurrent workers cannot both
     * observe the same tokens and both spend them. Returns {granted, remaining}
     * because a caller that had to re-read the count could see a different
     * state than the consume it just performed.
     */
    private const SCRIPT = <<<'LUA'
    local capacity = tonumber(ARGV[1])
    local per_second = tonumber(ARGV[2]) / 60.0
    local now = tonumber(ARGV[3])
    local requested = tonumber(ARGV[4])

    local tokens = tonumber(redis.call('GET', KEYS[1]))
    local refilled_at = tonumber(redis.call('GET', KEYS[2]))

    if tokens == nil or refilled_at == nil then
        tokens = capacity
        refilled_at = now
    end

    local elapsed = now - refilled_at
    if elapsed > 0 then
        local gained = math.floor(elapsed * per_second)
        if gained > 0 then
            tokens = math.min(capacity, tokens + gained)
            -- Advance by exactly what was earned, never to `now`. Advancing to
            -- `now` would discard the remainder on every call, so a loop
            -- polling faster than the refill interval would never refill.
            refilled_at = refilled_at + (gained / per_second)
            if refilled_at > now then
                refilled_at = now
            end
        end
    end

    local granted = math.min(tokens, requested)
    if granted < 0 then
        granted = 0
    end
    tokens = tokens - granted

    redis.call('SET', KEYS[1], tokens)
    redis.call('SET', KEYS[2], refilled_at)

    return {granted, tokens}
    LUA;

    public function __construct(
        private Connection $redis,
        private int $capacity,
        private int $refillPerMinute,
    ) {}

    /**
     * @return int tokens actually granted, between 0 and $tokens inclusive
     */
    public function tryConsume(int $tokens = 1): int
    {
        return $this->run(max(0, $tokens))[0];
    }

    public function available(): int
    {
        return $this->run(0)[1];
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Seconds until at least one token is available; 0 if one already is.
     */
    public function secondsUntilNextToken(): int
    {
        if ($this->available() > 0) {
            return 0;
        }

        $refilledAt = (float) ($this->redis->get(self::REFILLED_AT_KEY) ?? 0.0);
        $perSecond = $this->refillPerMinute / 60.0;

        $due = $refilledAt + (1.0 / $perSecond);

        return max(0, (int) ceil($due - $this->now()));
    }

    /**
     * @return array{0: int, 1: int} granted, remaining
     */
    private function run(int $requested): array
    {
        // Called via command() rather than the magic-resolved eval() because
        // Illuminate\Redis\Connections\Connection is `@mixin \Redis`
        // (the native phpredis extension class), so static analysis against
        // the abstract type resolves eval() to phpredis's 3-arg native
        // signature — not the flat, driver-normalised call Laravel actually
        // implements. command() is a real, unambiguous method on Connection
        // itself, so it type-checks cleanly and (for the predis client this
        // project is pinned to via REDIS_CLIENT) dispatches identically to
        // what eval() would have done, since PredisConnection has no eval()
        // override of its own.
        /** @var array{0: int, 1: int} $result */
        $result = $this->redis->command('eval', [
            self::SCRIPT,
            2,
            self::TOKENS_KEY,
            self::REFILLED_AT_KEY,
            (string) $this->capacity,
            (string) $this->refillPerMinute,
            (string) $this->now(),
            (string) $requested,
        ]);

        return [(int) $result[0], (int) $result[1]];
    }

    private function now(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }
}
