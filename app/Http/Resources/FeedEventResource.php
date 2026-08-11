<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeedEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FeedEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'level' => $event->level->value,
            'type' => $event->type,
            'message' => $event->message,
            'context' => $event->context,
            'occurred_at' => $event->occurred_at->toAtomString(),
        ];
    }
}
