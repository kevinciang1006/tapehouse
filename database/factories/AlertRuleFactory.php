<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Models\AlertRule;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'symbol_id' => Symbol::factory(),
            'metric' => AlertMetric::Price,
            'condition' => AlertCondition::Above,
            'threshold' => '230.00000000',
            'is_active' => true,
            'cooldown_seconds' => 60,
            'last_fired_at' => null,
        ];
    }
}
