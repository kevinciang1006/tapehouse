<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Batched writer for the audit path.
 *
 * At eight symbols the difference between this and an insert per tick is
 * nothing; the write path is shaped for the case where it is eight thousand.
 */
final class TickBuffer
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ?CarbonImmutable $lastFlushAt = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly int $bufferSize,
        private readonly int $flushMs,
    ) {}

    public function add(Quote $quote, int $symbolId): void
    {
        $this->lastFlushAt ??= CarbonImmutable::now();
        $this->rows[] = $quote->toTickRow($symbolId);

        if (count($this->rows) >= $this->bufferSize) {
            $this->flush();
        }
    }

    public function flushIfDue(): int
    {
        if ($this->rows === []) {
            return 0;
        }

        $elapsedMs = $this->lastFlushAt === null
            ? PHP_INT_MAX
            : (int) (CarbonImmutable::now()->getPreciseTimestamp(3) - $this->lastFlushAt->getPreciseTimestamp(3));

        return $elapsedMs >= $this->flushMs ? $this->flush() : 0;
    }

    /**
     * One multi-row insert, never one per tick.
     */
    public function flush(): int
    {
        if ($this->rows === []) {
            return 0;
        }

        $count = count($this->rows);

        $this->db->table('ticks')->insert($this->rows);

        $this->rows = [];
        $this->lastFlushAt = CarbonImmutable::now();

        return $count;
    }

    public function pending(): int
    {
        return count($this->rows);
    }
}
