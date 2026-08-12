<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

it('authorises a user on their own tape channel', function (): void {
    $user = User::factory()->create();

    actingAs($user);

    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-tape.'.$user->id,
    ])->assertSuccessful();
});

it('refuses a user on someone else\'s tape channel', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    actingAs($user);

    // The watchlist is per user; one operator must never see another's tape.
    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-tape.'.$other->id,
    ])->assertForbidden();
});

it('authorises any signed-in operator on the ops channel', function (): void {
    $user = User::factory()->create();

    actingAs($user);

    // Feed health is not per user — every operator sees the same feed.
    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-ops',
    ])->assertSuccessful();
});

it('refuses a guest on any channel', function (): void {
    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-ops',
    ])->assertStatus(403);
});
