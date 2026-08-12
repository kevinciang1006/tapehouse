<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<AlertEvent> */
class AlertEventFactory extends Factory
{
    protected $model = AlertEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            'price' => '230.06000000',
            'fired_at' => Carbon::now(),
        ];
    }
}
