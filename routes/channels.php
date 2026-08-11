<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// One operator must never receive another's tape: the watchlist is per user.
Broadcast::channel('tape.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

// Feed health is not per user — every signed-in operator sees the same feed.
Broadcast::channel('ops', function (User $user): bool {
    return true;
});
