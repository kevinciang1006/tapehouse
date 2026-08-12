<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AlertEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AlertEvent */
class AlertEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->alert_rule_id,
            'ticker' => $this->rule->symbol->ticker,
            'metric' => $this->rule->metric->value,
            'condition' => $this->rule->condition->value,
            'threshold' => (string) $this->rule->threshold,
            // Money stays a string, never a float, in every layer of transit.
            'price' => (string) $this->price,
            'fired_at' => $this->fired_at->toAtomString(),
        ];
    }
}
