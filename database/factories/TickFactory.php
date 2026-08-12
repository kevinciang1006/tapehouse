<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TickSource;
use App\Models\Symbol;
use App\Models\Tick;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Tick> */
class TickFactory extends Factory
{
    protected $model = Tick::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $quotedAt = Carbon::now()->subMilliseconds(40);

        return [
            'symbol_id' => Symbol::factory(),
            'price' => $this->faker->randomFloat(8, 1, 1000),
            'day_change' => $this->faker->randomFloat(8, -10, 10),
            'day_change_pct' => $this->faker->randomFloat(4, -5, 5),
            'source' => TickSource::WebSocket,
            'quoted_at' => $quotedAt,
            'received_at' => $quotedAt->copy()->addMilliseconds(40),
        ];
    }
}
