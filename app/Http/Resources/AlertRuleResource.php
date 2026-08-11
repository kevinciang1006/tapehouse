<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AlertRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AlertRule */
class AlertRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'symbol_id' => $this->symbol_id,
            'ticker' => $this->whenLoaded('symbol', fn () => $this->symbol->ticker),
            'metric' => $this->metric->value,
            'condition' => $this->condition->value,
            // Money stays a string: decimal(18,8) columns are never cast to
            // float, so a threshold does not lose precision in transit.
            'threshold' => (string) $this->threshold,
            'is_active' => $this->is_active,
            'cooldown_seconds' => $this->cooldown_seconds,
            'last_fired_at' => $this->last_fired_at?->toAtomString(),
        ];
    }
}
