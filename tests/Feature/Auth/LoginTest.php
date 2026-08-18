<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('logs an operator in with valid credentials', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);

    post('/login', ['email' => $user->email, 'password' => 'tapehouse'])
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
});

it('rejects a wrong password without revealing which field failed', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);

    post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('requires both fields', function (): void {
    post('/login', [])->assertSessionHasErrors(['email', 'password']);
});

it('logs an operator out', function (): void {
    $user = User::factory()->create();

    actingAs($user);
    post('/logout')->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

it('generates an https login form action behind a TLS-terminating proxy', function (): void {
    // Railway (and this app's production deploy generally) terminates TLS
    // at the edge and forwards to the container over plain HTTP, setting
    // X-Forwarded-Proto to say so. Without trustProxies() in
    // bootstrap/app.php, Laravel reads the raw (http) scheme of the
    // forwarded request and generates every URL — including this form's
    // action — as http://, which a browser on the https:// page blocks as
    // mixed content the moment the form submits.
    get('/login', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertSee('action="'.route('login').'"', false);

    expect(route('login'))->toStartWith('https://');
});

it('throttles rapid login attempts', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);

    for ($i = 0; $i < 5; $i++) {
        post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    // The 6th attempt within the same minute must be throttled, not
    // evaluated against the credentials at all — brute-forcing a password is
    // otherwise unbounded.
    post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(429);
});
