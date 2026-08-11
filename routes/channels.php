<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// One operator must never receive another's tape: the watchlist is per user.
// userId is a route-pattern string, not a route-model-bound int — a
// non-numeric segment (e.g. "4abc") would fail an int type-hint's implicit
// cast with a 500 instead of the 403 a forged channel name should get.
Broadcast::channel('tape.{userId}', function (User $user, string $userId): bool {
    return (string) $user->id === $userId;
});

// Feed health is not per user — every signed-in operator sees the same feed.
Broadcast::channel('ops', function (User $user): bool {
    return true;
});
