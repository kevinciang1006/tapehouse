<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedEventLevel;
use App\Models\FeedEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<FeedEvent> */
class FeedEventFactory extends Factory
{
    protected $model = FeedEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'level' => FeedEventLevel::Info,
            'type' => 'driver.transition',
            'message' => 'ws demoted → polling. credit budget exhausted.',
            'context' => ['from' => 'websocket', 'to' => 'polling'],
            'occurred_at' => Carbon::now(),
        ];
    }
}
