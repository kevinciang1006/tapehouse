<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Symbol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Symbol */
class SymbolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticker' => $this->ticker,
            'name' => $this->name,
            'asset_type' => $this->asset_type->value,
            'exchange' => $this->exchange,
            'currency' => $this->currency,
            // The tape formats prices per symbol, not per asset type: XAU/USD
            // is a forex pair that quotes to 2 places.
            'price_decimals' => $this->price_decimals,
        ];
    }
}
