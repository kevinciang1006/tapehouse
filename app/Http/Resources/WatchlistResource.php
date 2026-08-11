<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Watchlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Watchlist */
class WatchlistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'symbols' => SymbolResource::collection($this->whenLoaded('symbols')),
        ];
    }
}
