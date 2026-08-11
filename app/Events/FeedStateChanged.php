<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DriverState;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FeedStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DriverState $driver,
        public int $secondsInState,
        public int $reconnects,
        public ?string $lastError,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('ops');
    }

    public function broadcastAs(): string
    {
        return 'feed.state';
    }

    /**
     * @return array{driver: string, seconds_in_state: int, reconnects: int, last_error: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'driver' => $this->driver->value,
            'seconds_in_state' => $this->secondsInState,
            'reconnects' => $this->reconnects,
            'last_error' => $this->lastError,
        ];
    }
}
