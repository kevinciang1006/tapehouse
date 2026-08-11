<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{ticker: string, price: string, day_change: string|null, day_change_pct: string|null, source: string, quoted_at: string}>  $quotes
     */
    public function __construct(public int $userId, public array $quotes) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tape.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'quotes.updated';
    }

    /**
     * @return array{quotes: list<array<string, string|null>>}
     */
    public function broadcastWith(): array
    {
        // A flat array, never an Eloquent model: models serialise their whole
        // attribute set and re-query on the far side.
        return ['quotes' => $this->quotes];
    }
}
