<?php

declare(strict_types=1);

use App\Enums\DriverState;
use App\Events\FeedStateChanged;

it('broadcasts on the shared ops channel under a short name', function (): void {
    $event = new FeedStateChanged(DriverState::Polling, 41, 3, 'ws demoted');

    expect($event->broadcastAs())->toBe('feed.state')
        ->and($event->broadcastOn()->name)->toBe('private-ops');
});

it('broadcasts a flat array, never a model', function (): void {
    $payload = (new FeedStateChanged(DriverState::Polling, 41, 3, 'ws demoted'))->broadcastWith();

    expect($payload['driver'])->toBe('polling')
        ->and($payload['seconds_in_state'])->toBe(41)
        ->and($payload['reconnects'])->toBe(3)
        ->and($payload['last_error'])->toBe('ws demoted');
});
