<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Models\Symbol;
use App\Models\Tick;
use App\Services\Quotes\TickBuffer;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function bufferedQuote(string $price = '228.41'): Quote
{
    $at = CarbonImmutable::now()->setMicrosecond(123456);

    return new Quote('AAPL', $price, '1.82', '0.80', TickSource::Polling, $at, $at->addMilliseconds(40));
}

it('does not write until the buffer fills', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);

    for ($i = 0; $i < 199; $i++) {
        $buffer->add(bufferedQuote(), $symbol->id);
    }

    expect(Tick::count())->toBe(0)
        ->and($buffer->pending())->toBe(199);
});

it('flushes when the buffer reaches its size', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);

    for ($i = 0; $i < 200; $i++) {
        $buffer->add(bufferedQuote(), $symbol->id);
    }

    expect(Tick::count())->toBe(200)
        ->and($buffer->pending())->toBe(0);
});

it('inserts the whole batch as a single query', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 50, 1000);

    DB::enableQueryLog();
    for ($i = 0; $i < 50; $i++) {
        $buffer->add(bufferedQuote(), $symbol->id);
    }
    $inserts = array_filter(DB::getQueryLog(), fn (array $q): bool => str_starts_with($q['query'], 'insert'));
    DB::disableQueryLog();

    // The point of the buffer. Fifty inserts here would defeat it entirely.
    expect($inserts)->toHaveCount(1);
});

it('flushes on the time threshold even when under-full', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);
    $buffer->add(bufferedQuote(), $symbol->id);

    $buffer->flushIfDue();
    expect(Tick::count())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:01.001'));
    $buffer->flushIfDue();

    expect(Tick::count())->toBe(1);
    CarbonImmutable::setTestNow();
});

it('preserves sub-second timestamps through the raw insert', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 1, 1000);

    $buffer->add(bufferedQuote(), $symbol->id);

    // The raw query builder formats DateTimeInterface bindings without a
    // fractional part, so the buffer must hand it pre-formatted strings.
    $row = DB::table('ticks')->first();

    expect((string) $row->quoted_at)->toContain('.123456');
});

it('preserves full price precision', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 1, 1000);

    $buffer->add(bufferedQuote('12345.12345678'), $symbol->id);

    expect(Tick::sole()->price)->toBe('12345.12345678');
});

it('flushes whatever remains on demand', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);
    $buffer->add(bufferedQuote(), $symbol->id);

    expect($buffer->flush())->toBe(1)
        ->and(Tick::count())->toBe(1);
});

it('is safe to flush when empty', function (): void {
    $buffer = new TickBuffer(DB::connection(), 200, 1000);

    expect($buffer->flush())->toBe(0)
        ->and(Tick::count())->toBe(0);
});

it('stores timestamps at the correct absolute instant, not shifted by the session timezone', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 1, 1000);

    $buffer->add(bufferedQuote(), $symbol->id);

    // Ask Postgres itself how old the row is. A naive timestamp string written
    // into a timestamptz column is resolved using the session timezone, so an
    // unpinned session silently shifts every tick by the UTC offset. Comparing
    // against PHP's clock would not catch it — both sides shift together.
    $ageSeconds = (float) DB::selectOne(
        'SELECT EXTRACT(EPOCH FROM (now() - quoted_at)) AS age FROM ticks ORDER BY id DESC LIMIT 1'
    )->age;

    expect(abs($ageSeconds))->toBeLessThan(60.0);
});
