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
