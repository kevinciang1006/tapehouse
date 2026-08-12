<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertFired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $ruleId,
        public string $ticker,
        public string $metric,
        public string $condition,
        public string $threshold,
        public string $price,
        public string $firedAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tape.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'alert.fired';
    }

    /**
     * @return array{rule_id: int, ticker: string, metric: string, condition: string, threshold: string, price: string, fired_at: string}
     */
    public function broadcastWith(): array
    {
        // A flat array, never an Eloquent model: models serialise their whole
        // attribute set and re-query on the far side.
        return [
            'rule_id' => $this->ruleId,
            'ticker' => $this->ticker,
            'metric' => $this->metric,
            'condition' => $this->condition,
            'threshold' => $this->threshold,
            'price' => $this->price,
            'fired_at' => $this->firedAt,
        ];
    }
}
