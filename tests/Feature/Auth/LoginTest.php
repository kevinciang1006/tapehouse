<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
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
