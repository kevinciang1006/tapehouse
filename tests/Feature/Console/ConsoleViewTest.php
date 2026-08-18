<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('sends a guest to the login screen', function (): void {
    get('/')->assertRedirect('/login');
});

it('renders the console shell for an operator', function (): void {
    actingAs(User::factory()->create());

    get('/')->assertOk()->assertSee('TAPEHOUSE', false);
});

it('exposes the csrf token and the operator id the frontend needs', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    // echo.js subscribes to private-tape.{userId}; api.js sends X-CSRF-TOKEN.
    // Both read these meta tags, so a missing one breaks the console silently.
    get('/')
        ->assertOk()
        ->assertSee('name="csrf-token"', false)
        ->assertSee('name="user-id" content="'.$user->id.'"', false);
});

it('renders the login screen for a guest', function (): void {
    get('/login')->assertOk()->assertSee('Operator sign in', false);
});

it('states plainly that the demo runs on a trial key', function (): void {
    // The design requires this: an operator must know the feed degrades to
    // polling rather than discovering it as a fault.
    get('/login')->assertOk()->assertSee('polling', false);
});

it('puts the demo credentials on the login screen', function (): void {
    // This link gets opened cold — from a phone, or forwarded without the
    // email body — so it has to work standalone without a separate message
    // carrying the password.
    get('/login')->assertOk()->assertSee('operator@tapehouse.dev / tapehouse', false);
});

it('shows the Stop feed button outside production', function (): void {
    actingAs(User::factory()->create());

    get('/')->assertOk()->assertSee('id="stop-feed-btn"', false);
});

it('hides the Stop feed button in production', function (): void {
    // The control flag is one Redis key shared by every visitor to the
    // public demo, not a per-session toggle — see FeedControl. Hiding the
    // button here is the UI half of that lock; OpsApiTest covers the
    // backend half.
    app()['env'] = 'production';
    actingAs(User::factory()->create());

    get('/')->assertOk()->assertDontSee('id="stop-feed-btn"', false);
});
