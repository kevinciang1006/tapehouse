<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('authenticates an api request with a real login session, not just actingAs', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);

    // actingAs() sets the user in memory and never touches the session, so it
    // cannot catch a middleware stack that lacks StartSession. Only a real
    // login followed by a real cookie-bearing request proves the API is
    // reachable from a browser at all.
    post('/login', ['email' => $user->email, 'password' => 'tapehouse'])->assertRedirect();

    get('/api/symbols')->assertOk();
});

it('rejects a state-changing api request without a csrf token', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);
    post('/login', ['email' => $user->email, 'password' => 'tapehouse'])->assertRedirect();

    // PreventRequestForgery bypasses itself unconditionally whenever the app
    // is running unit tests (its own runningUnitTests() check short-circuits
    // before the token comparison), which is exactly what lets the rest of
    // this suite post without carrying a token. That self-bypass would also
    // hide the guard being missing entirely from the api group, so to prove
    // it is really wired in we swap in a subclass that forces the real
    // enforcement path for this one request only.
    app()->instance(PreventRequestForgery::class, new class(app(), app(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    post('/api/ops/feed/stop', [], ['Accept' => 'application/json'])
        ->assertStatus(419);
});
