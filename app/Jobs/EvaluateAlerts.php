<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertMetric;
use App\Events\AlertFired;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{symbol_id: int, price: string, day_change_pct: string|null}>  $samples
     */
    public function __construct(public array $samples) {}

    public function handle(): void
    {
        if ($this->samples === []) {
            return;
        }

        $bySymbol = [];
        foreach ($this->samples as $sample) {
            $bySymbol[$sample['symbol_id']] = $sample;
        }

        $rules = AlertRule::query()
            ->with('symbol')
            ->where('is_active', true)
            ->whereIn('symbol_id', array_keys($bySymbol))
            ->get();

        $now = CarbonImmutable::now();

        foreach ($rules as $rule) {
            $sample = $bySymbol[$rule->symbol_id];

            $observed = $rule->metric === AlertMetric::Price
                ? $sample['price']
                : $sample['day_change_pct'];

            if ($observed === null) {
                continue;
            }

            // Deliberate (float) casts: this is a threshold test, not an
            // accounting operation, and doubles carry ~15 significant digits
            // against realistic prices of <=14. The stored and broadcast
            // values stay strings.
            if (! $rule->condition->isSatisfiedBy((float) $observed, (float) $rule->threshold)) {
                continue;
            }

            // Cooldown stops a price oscillating around a threshold from
            // spamming the log once per tick.
            if ($rule->last_fired_at !== null
                && $now->diffInSeconds($rule->last_fired_at, true) < $rule->cooldown_seconds) {
                continue;
            }

            AlertEvent::create([
                'alert_rule_id' => $rule->id,
                'price' => $sample['price'],
                'fired_at' => $now,
            ]);

            $rule->forceFill(['last_fired_at' => $now])->save();

            event(new AlertFired(
                userId: $rule->user_id,
                ruleId: $rule->id,
                ticker: $rule->symbol->ticker,
                metric: $rule->metric->value,
                condition: $rule->condition->value,
                threshold: (string) $rule->threshold,
                price: $sample['price'],
                firedAt: $now->format('Y-m-d\TH:i:s.uP'),
            ));
        }
    }
}
