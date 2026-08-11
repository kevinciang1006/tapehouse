<?php

declare(strict_types=1);

use App\Models\Tick;
use Carbon\CarbonImmutable;

use function Pest\Laravel\artisan;

it('deletes ticks older than the retention window and keeps the rest', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
    config()->set('tapehouse.ticks.retention_hours', 24);

    $old = Tick::factory()->create(['quoted_at' => CarbonImmutable::now()->subHours(25)]);
    $fresh = Tick::factory()->create(['quoted_at' => CarbonImmutable::now()->subHours(1)]);

    artisan('tape:prune')->assertSuccessful();

    expect(Tick::find($old->id))->toBeNull()
        ->and(Tick::find($fresh->id))->not->toBeNull();

    CarbonImmutable::setTestNow();
});
