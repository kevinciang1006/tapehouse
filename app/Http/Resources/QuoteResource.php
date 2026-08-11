<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Upstream\DTO\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        /** @var Quote $quote */
        $quote = $this->resource;

        return [
            'ticker' => $quote->ticker,
            // Strings, not floats: a JSON number would round-trip a
            // numeric(18,8) through a double and lose the low digits.
            'price' => $quote->price,
            'day_change' => $quote->dayChange,
            'day_change_pct' => $quote->dayChangePct,
            'source' => $quote->source->value,
            'quoted_at' => $quote->quotedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
